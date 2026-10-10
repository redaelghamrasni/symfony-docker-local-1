<?php

namespace App\Controller;

use App\Entity\Address;
use App\Market\MarketContext;
use App\Market\MarketProfile;
use App\Market\Tax\TaxEngineRegistry;
use App\Service\CartService;
use App\Service\CurrencyService;
use App\Service\OrderFinalizer;
use App\Service\PaymentOutcome;
use App\Service\PayPalService;
use App\Service\QuoteConverter;
use App\Service\QuoteService;
use App\Service\SettingService;
use App\Service\TaxService;
use App\Service\StripeCustomerService;
use App\Entity\Cart;
use App\Entity\Order;
use App\Entity\Quote;
use App\Entity\User;
use App\Repository\AddressRepository;
use App\Repository\OrderRepository;
use App\Repository\QuoteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\StripeClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\ShippingService;
use App\Shipping\Adapter\CartCustomsAdapter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class CheckoutController extends AbstractController
{
    public function __construct(
        private CartService $cartService,
        private StripeClient $stripeClient,
        private PayPalService $payPalService,
        private EntityManagerInterface $entityManager,
        private ShippingService $shippingService,
        private CartCustomsAdapter $cartCustomsAdapter,
        private SettingService $settingService,
        private StripeCustomerService $stripeCustomerService,
        private AddressRepository $addressRepository,
        private OrderRepository $orderRepository,
        private QuoteRepository $quoteRepository,
        private QuoteService $quoteService,
        private QuoteConverter $quoteConverter,
        private OrderFinalizer $orderFinalizer,
        private CurrencyService $currencyService,
        private TaxService $taxService,
        private TaxEngineRegistry $taxEngineRegistry,
        private MarketContext $marketContext,
        private MarketProfile $marketProfile,
        private LoggerInterface $logger,
        // Channel loggers (see config/packages/monolog.yaml). These write on
        // success as well as failure: the record of a completed order is the
        // point, not a side effect of error handling.
        private LoggerInterface $checkoutLogger,
        private LoggerInterface $paymentLogger,
        private LoggerInterface $shippingLogger,
    ) {
    }

    #[Route('/checkout', name: 'app_checkout_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $cart = $this->cartService->getCurrentCart();

        if ($cart->isEmpty()) {
            return $this->redirectToRoute('app_cart_index');
        }

        /** @var User|null $user */
        $user = $this->getUser();

        $customerInfo = [];
        if ($user) {
            $customerInfo['email'] = $user->getEmail();
            $customerInfo['first_name'] = $user->getFirstName();
            $customerInfo['last_name'] = $user->getLastName();

            // Pre-fill address from saved default or most-recent order
            $savedAddr = $this->addressRepository->findDefaultShippingByUser($user->getId());
            if ($savedAddr) {
                $customerInfo['shipping_address']  = $savedAddr->getStreet();
                $customerInfo['shipping_city']     = $savedAddr->getCity();
                $customerInfo['shipping_postal']   = $savedAddr->getPostalCode();
                $customerInfo['shipping_province'] = $savedAddr->getProvince();
                $customerInfo['phone']             = $savedAddr->getPhone();
            } else {
                $lastOrder = $this->orderRepository->findLastByUser($user);
                if ($lastOrder) {
                    $customerInfo['shipping_address']  = $lastOrder->getShippingStreet();
                    $customerInfo['shipping_city']     = $lastOrder->getShippingCity();
                    $customerInfo['shipping_postal']   = $lastOrder->getShippingPostalCode();
                    $customerInfo['shipping_province'] = $lastOrder->getShippingProvince();
                    $customerInfo['phone']             = $lastOrder->getCustomerPhone();
                }
            }
        }

        return $this->render('checkout/index.html.twig', [
            'cart'              => $cart,
            'stripe_public_key' => $_ENV['STRIPE_PUBLIC_KEY'] ?? $_SERVER['STRIPE_PUBLIC_KEY'] ?? getenv('STRIPE_PUBLIC_KEY'),
            'paypal_client_id'  => $_ENV['PAYPAL_CLIENT_ID'] ?? $_SERVER['PAYPAL_CLIENT_ID'] ?? getenv('PAYPAL_CLIENT_ID'),
            'customer_info'     => $customerInfo,
            // Localised country list for the shipping selector, and the shop's
            // home country as the default when the customer has none yet.
            'countries'         => $this->marketContext->countryNames($request->getLocale()),
            'home_country'      => $this->marketContext->homeCountry(),
            // Sub-national regions for the home market; empty → free-text field.
            'regions'           => $this->marketProfile->regions(),
        ]);
    }

    #[Route('/checkout/save-customer-info', name: 'app_checkout_save_customer_info', methods: ['POST'])]
    public function saveCustomerInfo(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?: [];
        /** @var User|null $user */
        $user = $this->getUser();

        if ($user) {
            $email = $user->getEmail();
            $name  = $user->getFirstName() . ' ' . $user->getLastName();
        } else {
            $email = filter_var(trim($data['customer_email'] ?? ''), FILTER_VALIDATE_EMAIL);
            $name  = trim($data['customer_name'] ?? '');
            if (!$email) {
                return $this->json(['error' => 'Adresse e-mail invalide.'], 400);
            }
        }

        $phone            = trim($data['customer_phone'] ?? '');
        $shippingAddress  = trim($data['checkout_shipping_address'] ?? '');
        $shippingCity     = trim($data['checkout_shipping_city'] ?? '');
        $shippingPostal   = trim($data['checkout_shipping_postal'] ?? '');
        $shippingProvince = trim($data['checkout_shipping_province'] ?? '');
        // ISO 3166-1 alpha-2; default to the shop's home country when absent,
        // and reject anything unrecognised so a bad value never reaches the
        // carrier as a destination.
        $shippingCountry  = strtoupper(trim($data['checkout_shipping_country'] ?? ''));
        if ($shippingCountry === '' || !$this->marketContext->isValidCountry($shippingCountry)) {
            $shippingCountry = $this->marketContext->homeCountry();
        }

        $session = $request->getSession();
        $session->set('checkout_email', $email);
        $session->set('checkout_name', $name !== '' ? $name : 'Client');
        $session->set('checkout_phone', $phone);
        $session->set('checkout_shipping_address',  $shippingAddress);
        $session->set('checkout_shipping_city',     $shippingCity);
        $session->set('checkout_shipping_postal',   $shippingPostal);
        $session->set('checkout_shipping_province', $shippingProvince);
        $session->set('checkout_shipping_country',  $shippingCountry);
        $session->set('checkout_billing_same',    (bool)($data['checkout_billing_same'] ?? true));
        $session->set('checkout_billing_address',   trim($data['checkout_billing_address'] ?? ''));
        $session->set('checkout_billing_city',      trim($data['checkout_billing_city'] ?? ''));
        $session->set('checkout_billing_postal',    trim($data['checkout_billing_postal'] ?? ''));
        $session->set('checkout_billing_province',  trim($data['checkout_billing_province'] ?? ''));

        // Sync customer info (incluant Customer Stripe) to the already-created PaymentIntent
        $piId = $session->get('checkout_pi_id');
        if ($piId) {
            try {
                $pi = $this->stripeClient->paymentIntents->retrieve($piId);

                if ($pi->customer) {
                    $addressPayload = $shippingAddress !== '' ? [
                        'line1'       => $shippingAddress,
                        'city'        => $shippingCity,
                        'postal_code' => $shippingPostal,
                        'state'       => $shippingProvince,
                        'country'     => 'CA',
                    ] : null;

                    $this->stripeClient->customers->update($pi->customer, [
                        'email'   => $email,
                        'name'    => $name !== '' ? $name : 'Client',
                        'phone'   => $phone ?: null,
                        'address' => $addressPayload,
                    ]);
                }

                $this->stripeClient->paymentIntents->update($piId, [
                    'metadata' => [
                        'customer_email' => $email,
                        'customer_name'  => $name !== '' ? $name : 'Client',
                    ],
                ]);
            } catch (\Throwable $e) {
                // Non-fatal: payment still works without the customer metadata.
                // Logged anyway — a rising rate here means the Stripe customer
                // records are drifting out of sync with ours.
                $this->paymentLogger->warning('payment.customer_sync_failed', [
                    'provider'       => 'stripe',
                    'payment_intent' => $piId,
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        // Capture the customer + address onto the quote now that the session holds
        // them. This is typically the last checkout step before payment confirm,
        // so it is what ensures the quote carries a deliverable address.
        $this->syncStripeQuote($session, $this->cartService->getCurrentCart());

        return $this->json(['ok' => true]);
    }

    #[Route('/checkout/create-payment-intent', name: 'app_checkout_create_payment_intent', methods: ['POST'])]
    public function createPaymentIntent(Request $request): JsonResponse
    {
        $cart = $this->cartService->getCurrentCart();
        if (count($cart->getItems()) === 0) {
            return $this->json(['error' => 'Votre panier est vide.'], 400);
        }

        $data  = json_decode($request->getContent(), true) ?: [];
        $email = filter_var(trim($data['customer_email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $name  = trim($data['customer_name'] ?? '');
        $phone = trim($data['customer_phone'] ?? '');

        /** @var User|null $user */
        $user = $this->getUser();
        $savedAddr = null;

        if ($user) {
            $email = $email ?: $user->getEmail();
            $name  = $name !== '' ? $name : trim($user->getFirstName() . ' ' . $user->getLastName());
            $savedAddr = $this->addressRepository->findDefaultShippingByUser($user->getId());
            if ($phone === '') {
                $phone = $savedAddr?->getPhone() ?? '';
            }
        }

        if ($email) {
            $session = $request->getSession();
            $session->set('checkout_email', $email);
            $session->set('checkout_name', $name !== '' ? $name : 'Client');
        }

        // The cart decides the currency — never the request body. Amounts are
        // converted through the service so zero-decimal currencies (JPY and
        // friends) are not multiplied by 100 when they are added later.
        $currency = $cart->getCurrency();
        $subtotal = (float) $cart->getTotal();
        $amount   = $this->currencyService->toMinorUnits($subtotal, $currency);
        if ($amount <= 0) {
            return $this->json(['error' => 'Montant de paiement invalide.'], 400);
        }

        try {
            if ($user) {
                // Utilisateur connecté : Customer Stripe persistant, réutilisé d'une commande à l'autre
                $customerId = $this->stripeCustomerService->getOrCreateCustomer($user, $phone ?: null, $savedAddr);
            } else {
                // Invité : Customer Stripe créé à la volée (non persisté côté app)
                $guestAddress = null;
                if (!empty($data['checkout_shipping_address'])) {
                    $guestAddress = [
                        'line1'       => trim($data['checkout_shipping_address']),
                        'city'        => trim($data['checkout_shipping_city'] ?? ''),
                        'postal_code' => trim($data['checkout_shipping_postal'] ?? ''),
                        'state'       => trim($data['checkout_shipping_province'] ?? ''),
                        'country'     => 'CA',
                    ];
                }

                $customer = $this->stripeClient->customers->create([
                    'email'   => $email ?: null,
                    'name'    => $name ?: null,
                    'phone'   => $phone ?: null,
                    'address' => $guestAddress,
                ]);
                $customerId = $customer->id;
            }

            $paymentIntent = $this->stripeClient->paymentIntents->create([
                'amount'                    => $amount,
                'currency'                  => $this->currencyService->forProvider($currency),
                'customer'                  => $customerId,
                'automatic_payment_methods' => ['enabled' => true],
                'metadata' => [
                    'cart_id'        => $cart->getId(),
                    'customer_email' => $email ?: '',
                    'customer_name'  => $name  ?: '',
                ],
            ]);
        } catch (\Throwable $e) {
            // Stable message + structured context: the message is what you
            // aggregate and alert on, the context is what you investigate with.
            // Interpolating $e->getMessage() into the message would make every
            // occurrence a unique string and defeat both.
            $this->paymentLogger->error('payment.intent.create_failed', [
                'provider'     => 'stripe',
                'cart_id'      => $cart->getId(),
                'amount_minor' => $amount,
                'currency'     => $this->currencyService->forProvider($currency),
                'error_class'  => $e::class,
                'error'        => $e->getMessage(),
            ]);

            return $this->json(['error' => 'Erreur de connexion au serveur de paiement.'], 502);
        }

        // Store PI ID so we can update the amount later
        $session = $request->getSession();
        $session->set('checkout_pi_id', $paymentIntent->id);

        // Pre-create the quote and link this PaymentIntent now, so a quote the
        // webhook can convert exists from the very first payment step — even if
        // the browser never returns. This replaces the old pending-Order
        // pre-persist; the quote is the mutable pre-payment object.
        $this->syncStripeQuote($session, $cart);

        $this->paymentLogger->info('payment.intent.created', [
            'provider'       => 'stripe',
            'payment_intent' => $paymentIntent->id,
            'cart_id'        => $cart->getId(),
            'amount_minor'   => $amount,
            'currency'       => $this->currencyService->forProvider($currency),
            'is_guest'       => $this->getUser() === null,
        ]);

        return $this->json([
            'clientSecret'   => $paymentIntent->client_secret,
            'publishableKey' => $_ENV['STRIPE_PUBLIC_KEY'] ?? $_SERVER['STRIPE_PUBLIC_KEY'] ?? getenv('STRIPE_PUBLIC_KEY'),
        ]);
    }

    #[Route('/checkout/update-payment', name: 'app_checkout_update_payment', methods: ['POST'])]
    public function updatePayment(Request $request): JsonResponse
    {
        $data     = json_decode($request->getContent(), true) ?: [];
        $province = strtoupper(trim($data['province'] ?? ''));
        $shipping = (float) ($data['shipping_amount'] ?? 0.0);

        $shippingCarrier   = trim((string) ($data['shipping_carrier'] ?? ''));
        $shippingMethod    = trim((string) ($data['shipping_method'] ?? ''));
        $shippingReference = trim((string) ($data['shipping_reference'] ?? ''));

        $cart     = $this->cartService->getCurrentCart();
        $currency = $cart->getCurrency();
        $subtotal = (float) $cart->getTotal();

        $session  = $request->getSession();
        $country  = $session->get('checkout_shipping_country') ?: $this->marketContext->homeCountry();

        // Tax comes from the active market's engine, not from Canada-specific
        // code. The quote's lines are recomputed from the same engine + inputs in
        // QuoteService::populate, so what is charged and what is stored match.
        $quote      = $this->taxEngineRegistry->active()->quote($subtotal, $country, $province ?: null);
        $taxes      = (float) $quote->total();
        $grandTotal = $subtotal + $taxes + $shipping;

        // Persist amounts to session
        $session->set('checkout_subtotal',         $subtotal);
        $session->set('checkout_shipping_amount',  $shipping);
        $session->set('checkout_shipping_carrier',   $shippingCarrier !== '' ? $shippingCarrier : null);
        $session->set('checkout_shipping_method',    $shippingMethod !== '' ? $shippingMethod : null);
        $session->set('checkout_shipping_reference',  $shippingReference !== '' ? $shippingReference : null);
        $session->set('checkout_grand_total',      $grandTotal);

        // Update Stripe PaymentIntent amount
        $piId = $session->get('checkout_pi_id');
        if ($piId) {
            try {
                $this->stripeClient->paymentIntents->update($piId, [
                    'amount'   => $this->currencyService->toMinorUnits($grandTotal, $currency),
                    'currency' => $this->currencyService->forProvider($currency),
                ]);
            } catch (\Throwable $e) {
                // Non-fatal for the customer (Stripe's form shows the right
                // total), but it means our PaymentIntent and our cart disagree
                // on the amount — worth seeing if it starts happening often.
                $this->paymentLogger->warning('payment.intent.amount_update_failed', [
                    'provider'       => 'stripe',
                    'payment_intent' => $piId,
                    'amount_minor'   => $this->currencyService->toMinorUnits($grandTotal, $currency),
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        // Keep the quote in sync now that the amount, shipping and tax are
        // finalized, and (re)link the PaymentIntent. This is what lets the webhook
        // and the browser return convert an order without the session — see
        // docs/quote-lifecycle-plan.md. A storage hiccup never breaks the checkout
        // UI: the totals are still returned (syncStripeQuote swallows + logs).
        $this->syncStripeQuote($session, $cart);

        return $this->json([
            'ok'          => true,
            'subtotal'    => $subtotal,
            'taxes'       => $taxes,
            'shipping'    => $shipping,
            'grand_total' => $grandTotal,
        ]);
    }

    #[Route('/checkout/success', name: 'app_checkout_success', methods: ['GET'])]
    public function success(Request $request): Response
    {
        $session = $request->getSession();

        if ($request->query->get('redirect_status') === 'succeeded') {
            $cart            = $this->cartService->getCurrentCart();
            $claimedIntentId = $request->query->get('payment_intent');

            // Last-resort: if no quote was created during checkout (update-payment
            // never ran, or syncing failed), build one from the session now so a
            // paid customer still gets an order.
            $quote = $this->findCheckoutQuote($session, 'stripe', $claimedIntentId);
            if (!$quote) {
                $quote = $this->syncStripeQuote($session, $cart);
            }

            if ($quote) {
                // Quote → Order (idempotent; the webhook may already have done it).
                $order     = $this->quoteConverter->convert($quote);
                $outcome   = $this->buildStripeOutcome($order, $claimedIntentId);
                $performed = $this->orderFinalizer->finalizePaid($order, $outcome);

                // Address bookkeeping belongs to the customer, not the payment;
                // do it once, on the path that actually finalized the order.
                if ($performed) {
                    $this->saveAddressFromOrder($order);
                    $this->entityManager->flush();
                }
            } else {
                $this->paymentLogger->error('checkout.success.quote_not_found', [
                    'provider'       => 'stripe',
                    'payment_intent' => $claimedIntentId,
                    'action'         => 'succeeded browser return with no quote to convert; investigate',
                ]);
            }

            // Session/cart clearing is browser-side by nature and must not break
            // anything if the webhook already finalized the order (it did not
            // touch the session). It simply runs.
            $this->clearCheckoutSession($session);
            $this->cartService->clear();
        }

        return $this->render('checkout/success.html.twig');
    }

    // ── PayPal routes ─────────────────────────────────────────────────────

    #[Route('/checkout/paypal/create-order', name: 'app_checkout_paypal_create_order', methods: ['POST'])]
    public function paypalCreateOrder(Request $request): JsonResponse
    {
        $cart = $this->cartService->getCurrentCart();
        if ($cart->isEmpty()) {
            return $this->json(['error' => 'Cart is empty'], 400);
        }

        $currency = $cart->getCurrency();

        $session    = $request->getSession();
        $grandTotal = (float) ($session->get('checkout_grand_total') ?: $cart->getTotal());

        try {
            $result = $this->payPalService->createOrder($grandTotal);

            $this->paymentLogger->info('payment.paypal.order_created', [
                'provider'        => 'paypal',
                'paypal_order_id' => $result['id'] ?? null,
                'cart_id'         => $cart->getId(),
                'total'           => $grandTotal,
                'currency'        => $this->currencyService->forProvider($currency),
            ]);

            return $this->json(['id' => $result['id']]);
        } catch (\Throwable $e) {
            $this->paymentLogger->error('payment.paypal.create_failed', [
                'provider'    => 'paypal',
                'cart_id'     => $cart->getId(),
                'total'       => $grandTotal,
                'currency'    => $this->currencyService->forProvider($currency),
                'error_class' => $e::class,
                'error'       => $e->getMessage(),
            ]);

            // The exception text goes to the log, not to the browser: it can
            // carry internal detail, and it is useless to the customer.
            return $this->json(['error' => 'Erreur de connexion au serveur de paiement.'], 502);
        }
    }

    #[Route('/checkout/paypal/capture', name: 'app_checkout_paypal_capture', methods: ['POST'])]
    public function paypalCapture(Request $request): JsonResponse
    {
        $data        = json_decode($request->getContent(), true) ?? [];
        $paypalOrderId = $data['orderId'] ?? null;

        if (!$paypalOrderId) {
            return $this->json(['error' => 'Missing PayPal order ID'], 400);
        }

        try {
            $capture = $this->payPalService->captureOrder($paypalOrderId);
        } catch (\Throwable $e) {
            $this->paymentLogger->error('payment.paypal.capture_failed', [
                'provider'        => 'paypal',
                'paypal_order_id' => $paypalOrderId,
                'error_class'     => $e::class,
                'error'           => $e->getMessage(),
            ]);

            return $this->json(['error' => 'Erreur lors de la finalisation du paiement.'], 502);
        }

        if (($capture['status'] ?? '') !== 'COMPLETED') {
            // A customer who reaches this has been through PayPal's flow and
            // still has no order — worth knowing about even though it is a
            // "normal" outcome from the code's point of view.
            $this->paymentLogger->warning('payment.paypal.capture_not_completed', [
                'provider'        => 'paypal',
                'paypal_order_id' => $paypalOrderId,
                'status'          => $capture['status'] ?? null,
            ]);

            return $this->json(['error' => 'PayPal capture not completed: ' . ($capture['status'] ?? '')], 400);
        }

        $session = $request->getSession();
        $cart    = $this->cartService->getCurrentCart();

        // Find the quote this PayPal order settles; create one from the session if
        // none exists yet (a PayPal-only checkout where no Stripe step ever ran).
        $quote = $this->findCheckoutQuote($session, 'paypal', $paypalOrderId);
        if (!$quote) {
            $quote = $this->quoteService->upsert($session, $cart, $this->getUser());
        }

        if ($quote) {
            // This quote is settled by PayPal, not by any Stripe PaymentIntent it
            // may have carried: set its provider reference to PayPal. The converter
            // then stamps no Stripe id on the order, so no stray Stripe webhook can
            // ever convert/finalize it.
            $quote->setPayment('paypal', $paypalOrderId);
            $this->entityManager->flush();

            // Quote → Order (idempotent).
            $order = $this->quoteConverter->convert($quote);

            $cap              = $capture['purchase_units'][0]['payments']['captures'][0]['amount'] ?? null;
            $capturedAmount   = $cap['value'] ?? null;
            $capturedCurrency = isset($cap['currency_code']) ? strtolower((string) $cap['currency_code']) : null;

            $payerEmail = $capture['payment_source']['paypal']['email_address']
                ?? $capture['payer']['email_address']
                ?? null;

            $outcome = new PaymentOutcome(
                provider: 'paypal',
                providerConfirmed: true, // capture status was COMPLETED
                paidCurrency: $capturedCurrency,
                paidMinor: $capturedAmount !== null
                    ? $this->currencyService->toMinorUnits((string) $capturedAmount, $order->getCurrency())
                    : null,
                reference: $paypalOrderId,
                paymentMethod: 'paypal',
                paymentBrand: $payerEmail,
            );

            $performed = $this->orderFinalizer->finalizePaid($order, $outcome);

            if ($performed) {
                $this->saveAddressFromOrder($order);
                $this->entityManager->flush();
            }

            $this->clearCheckoutSession($session);
            $this->cartService->clear();
        }

        $successUrl = $this->generateUrl(
            'app_checkout_success',
            ['_locale' => $request->getLocale(), 'paypal' => '1'],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return $this->json(['redirectUrl' => $successUrl]);
    }

    // ── Shipping rates ────────────────────────────────────────────────────

    #[Route('/checkout/shipping-rates', name: 'app_checkout_shipping_rates', methods: ['POST'])]
    public function getShippingRates(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        if (empty($data['zip']) || empty($data['city'])) {
            return $this->json(['error' => 'Adresse incomplète'], 400);
        }

        $cart       = $this->cartService->getCurrentCart();
        $currency    = $cart->getCurrency();
        $totalItems  = 0;
        $totalWeight = 0.0;
        foreach ($cart->getItems() as $item) {
            $quantity    = $item->getQuantity();
            $totalItems += $quantity;
            $totalWeight += (float) ($item->getArticle()?->getWeight() ?? 0.5) * $quantity;
        }

        // Free shipping threshold
        $freeThreshold = $this->settingService->getFloat('shipping.free_threshold', 0.0);
        $cartTotal     = (float) $cart->getTotal();

        if ($freeThreshold > 0 && $cartTotal >= $freeThreshold) {
            return $this->json(['rates' => [[
                'object_id' => 'free_shipping',
                'carrier'   => 'Standard',
                'service'   => 'Livraison gratuite',
                'price'     => '0.00',
                'currency'  => $this->currencyService->forProvider($currency),
                'days'      => null,
            ]]]);
        }

        try {
            $rates = $this->shippingService->getRates(
                [
                    'name'    => $data['name'] ?? 'Client',
                    'street1' => $data['address'] ?? '',
                    'city'    => $data['city'],
                    'zip'     => $data['zip'],
                    'state'   => $data['province'] ?? null,
                    'country' => $data['country'] ?? $this->marketContext->homeCountry(),
                    'phone'   => $data['phone'] ?? '',
                    'email'   => $data['email'] ?? '',
                ],
                [
                    'weight' => max(0.5, round($totalWeight, 3)),
                    'length' => '30',
                    'width'  => '20',
                    'height' => '15',
                ],
                // Declared contents, attached only when the parcel crosses a
                // border (ShippingService decides). Goods are declared as made
                // in the shop's home country by default.
                $this->cartCustomsAdapter->fromCart($cart, $this->marketContext->homeCountry()),
            );
        } catch (\Throwable $e) {
            // A failure here stalls the customer mid-checkout with no way to
            // pick a shipping option, so it is an error, not a nuisance.
            $this->shippingLogger->error('shipping.rates.lookup_failed', [
                'provider'    => 'shippo',
                'province'    => $data['province'] ?? null,
                'postal_code' => $data['zip'] ?? null,
                'item_count'  => $totalItems,
                'error_class' => $e::class,
                'error'       => $e->getMessage(),
            ]);

            return $this->json(['error' => 'Erreur lors du chargement des tarifs.'], 502);
        }

        $this->shippingLogger->info('shipping.rates.retrieved', [
            'provider'   => 'shippo',
            'province'   => $data['province'] ?? null,
            'rate_count' => count($rates),
            'item_count' => $totalItems,
        ]);

        return $this->json(['rates' => $rates]);
    }

    // ── Shared helpers ────────────────────────────────────────────────────

    /**
     * Keeps the session's quote in sync with the session + cart and (re)links the
     * current Stripe PaymentIntent to it, writing the quote id into the PI
     * metadata as the webhook's fallback lookup. Never throws into the checkout
     * flow: a storage/Stripe hiccup is logged, and the quote (or null) is returned
     * so the caller can carry on. This is the Stripe-side entry point; the quote
     * itself stays provider-neutral (QuoteService never sets payment linkage).
     */
    private function syncStripeQuote(SessionInterface $session, Cart $cart): ?Quote
    {
        try {
            $quote = $this->quoteService->upsert($session, $cart, $this->getUser());
        } catch (\Throwable $e) {
            $this->paymentLogger->error('checkout.quote.upsert_failed', [
                'cart_id' => $cart->getId(),
                'error'   => $e->getMessage(),
                'action'  => 'quote not synced; webhook/browser-return may fail to convert',
            ]);

            return null;
        }

        if (!$quote) {
            return null;
        }

        // Link the PaymentIntent to the quote when it is not already (or when the
        // PI changed). (provider, reference) is the webhook's primary lookup; the
        // quote id in the PI metadata is its fallback.
        $piId = $session->get('checkout_pi_id');
        if ($piId && $quote->getPaymentReference() !== $piId) {
            $quote->setPayment('stripe', $piId);
            $this->entityManager->flush();
            $this->linkQuoteToStripeMetadata($piId, (int) $quote->getId());
        }

        return $quote;
    }

    /**
     * Writes the quote id into the PaymentIntent metadata — the webhook's fallback
     * lookup when the (provider, reference) pair cannot be matched. Stripe-specific
     * by nature, so it lives here on the Stripe path and never fails the flow.
     */
    private function linkQuoteToStripeMetadata(string $piId, int $quoteId): void
    {
        try {
            $this->stripeClient->paymentIntents->update($piId, [
                'metadata' => ['quote_id' => (string) $quoteId],
            ]);
        } catch (\Throwable $e) {
            $this->paymentLogger->warning('payment.intent.metadata_update_failed', [
                'provider'       => 'stripe',
                'payment_intent' => $piId,
                'quote_id'       => $quoteId,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    /**
     * Finds the quote a payment should convert: the one remembered in the session,
     * or — failing that — the one carrying the claimed provider reference.
     */
    private function findCheckoutQuote(SessionInterface $session, string $provider, ?string $reference): ?Quote
    {
        $id = $session->get('checkout_quote_id');
        if ($id) {
            $quote = $this->quoteRepository->find($id);
            if ($quote) {
                return $quote;
            }
        }

        if ($reference) {
            return $this->quoteRepository->findOneByPaymentReference($provider, $reference);
        }

        return null;
    }

    /**
     * Asks Stripe what actually happened to this order's payment and packages it
     * for the finalizer. The authoritative PaymentIntent is the one stored on the
     * order (set server-side), not the id the browser passed back; the claimed id
     * is only a fallback for the pre-webhook build path. A retrieval failure
     * yields an unconfirmed outcome, which the finalizer flags rather than trusts.
     */
    private function buildStripeOutcome(Order $order, ?string $claimedIntentId): PaymentOutcome
    {
        $piId = $order->getStripePaymentIntentId() ?: $claimedIntentId;
        if (!$piId) {
            return new PaymentOutcome('stripe', false, null, null, null);
        }

        try {
            $pi = $this->stripeClient->paymentIntents->retrieve($piId, ['expand' => ['payment_method']]);
        } catch (\Throwable $e) {
            $this->paymentLogger->warning('payment.details_capture_failed', [
                'provider'       => 'stripe',
                'payment_intent' => $piId,
                'consequence'    => 'order finalized without card brand or last4; flagged unverified',
                'error'          => $e->getMessage(),
            ]);

            return new PaymentOutcome('stripe', false, null, null, $piId, 'card');
        }

        $method = 'card';
        $brand  = null;
        $last4  = null;
        $pm     = $pi->payment_method ?? null;
        if (is_object($pm)) {
            $method = $pm->type ?? 'card';
            $card   = $pm->card ?? null;
            if ($card !== null) {
                $brand = $card->brand ?? null;
                $last4 = $card->last4 ?? null;
            }
        } else {
            $method = ($pi->payment_method_types[0] ?? null) ?: 'card';
        }

        return new PaymentOutcome(
            provider: 'stripe',
            providerConfirmed: ($pi->status ?? null) === 'succeeded',
            paidCurrency: $pi->currency !== null ? strtolower((string) $pi->currency) : null,
            paidMinor: isset($pi->amount_received) ? (int) $pi->amount_received : null,
            reference: $piId,
            paymentMethod: $method,
            paymentBrand: $brand,
            paymentLast4: $last4,
        );
    }

    private function saveAddressFromOrder(Order $order): void
    {
        $user = $order->getUser();
        if (!$user) {
            return;
        }

        // Only save if no default shipping address exists yet
        $existing = $this->addressRepository->findDefaultShippingByUser($user->getId());
        if ($existing) {
            // Update existing default address with latest info
            $existing->setStreet($order->getShippingStreet());
            $existing->setCity($order->getShippingCity());
            $existing->setPostalCode($order->getShippingPostalCode());
            $existing->setProvince($order->getShippingProvince());
            if ($order->getCustomerPhone()) {
                $existing->setPhone($order->getCustomerPhone());
            }
            return;
        }

        $addr = new Address();
        $addr->setUser($user);
        $addr->setType(Address::TYPE_SHIPPING);
        $addr->setFirstName($order->getCustomerFirstName());
        $addr->setLastName($order->getCustomerLastName());
        $addr->setStreet($order->getShippingStreet());
        $addr->setCity($order->getShippingCity());
        $addr->setPostalCode($order->getShippingPostalCode());
        $addr->setProvince($order->getShippingProvince());
        $addr->setPhone($order->getCustomerPhone());
        $addr->setIsDefault(true);
        $this->entityManager->persist($addr);
    }

    private function clearCheckoutSession(SessionInterface $session): void
    {
        foreach ([
            'checkout_email', 'checkout_name', 'checkout_phone',
            'checkout_shipping_address', 'checkout_shipping_city',
            'checkout_shipping_postal', 'checkout_shipping_province',
            'checkout_shipping_country',
            'checkout_billing_same', 'checkout_billing_address',
            'checkout_billing_city', 'checkout_billing_postal', 'checkout_billing_province',
            'checkout_pi_id', 'checkout_quote_id', 'checkout_subtotal', 'checkout_shipping_amount',
            'checkout_shipping_carrier', 'checkout_shipping_method', 'checkout_shipping_reference',
            'checkout_grand_total',
        ] as $key) {
            $session->remove($key);
        }
    }

    #[Route('/checkout/tax', name: 'app_checkout_tax', methods: ['POST'])]
    public function calculateTaxApi(Request $request): JsonResponse
    {
        $data     = json_decode($request->getContent(), true) ?? [];
        $province = $data['province'] ?? 'QC';
        $cart     = $this->cartService->getCurrentCart();
        $tax = $this->taxService->calculateTax((float) $cart->getTotal(), $province);

        return $this->json($tax);
    }
}
