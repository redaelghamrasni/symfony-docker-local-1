<?php

namespace App\Controller;

use App\Entity\Address;
use App\Market\MarketContext;
use App\Market\MarketProfile;
use App\Market\Tax\TaxEngineRegistry;
use App\Market\Tax\TaxQuote;
use App\Service\CartService;
use App\Service\CurrencyService;
use App\Service\OrderFinalizer;
use App\Service\PaymentOutcome;
use App\Service\PayPalService;
use App\Service\SettingService;
use App\Service\TaxService;
use App\Service\StripeCustomerService;
use App\Entity\Cart;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\OrderTaxLine;
use App\Entity\User;
use App\Repository\AddressRepository;
use App\Repository\OrderRepository;
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
        $request->getSession()->set('checkout_pi_id', $paymentIntent->id);

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
        // code. The order's lines are recomputed from the same engine + inputs
        // in populateOrderFromSession, so what is charged and what is stored match.
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

        // Pre-persist the order as `pending` now that the amount is finalized and
        // the session holds everything. This is what makes the webhook (and the
        // browser return) able to finalize an order without the session — see
        // docs/stripe-webhook-plan.md. Never lets a storage hiccup break the
        // checkout UI: the totals are still returned.
        try {
            $this->upsertPendingOrder($session, $cart);
        } catch (\Throwable $e) {
            $this->paymentLogger->error('checkout.pending_order_upsert_failed', [
                'cart_id' => $cart->getId(),
                'error'   => $e->getMessage(),
                'action'  => 'order not pre-persisted; webhook will rely on browser return',
            ]);
        }

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

            $order = $this->findFinalizableOrder($session, $claimedIntentId);

            // Fallback: the order was not pre-persisted (update-payment never ran,
            // or its upsert failed). Build it from the session now so a paid
            // customer still gets an order, exactly as before the webhook work.
            if (!$order) {
                $order = $this->upsertPendingOrder($session, $cart);
            }

            if ($order) {
                $outcome   = $this->buildStripeOutcome($order, $claimedIntentId);
                $performed = $this->orderFinalizer->finalizePaid($order, $outcome);

                // Address bookkeeping belongs to the customer, not the payment;
                // do it once, on the path that actually finalized the order.
                if ($performed) {
                    $this->saveAddressFromOrder($order);
                    $this->entityManager->flush();
                }
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

        $order = $this->findFinalizableOrder($session, null);
        if (!$order) {
            $order = $this->upsertPendingOrder($session, $cart);
        }

        if ($order) {
            // This order is being paid through PayPal, not the Stripe
            // PaymentIntent pre-persisted at update-payment: drop the PI link so
            // no stray Stripe webhook could ever finalize it, and flush before
            // the finalizer's refresh so the change survives.
            $order->setStripePaymentIntentId(null);
            $this->entityManager->flush();

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
     * Creates or updates the session's `pending` order from the current session
     * and cart, links the Stripe PaymentIntent both ways, and remembers the
     * order id in the session. Returns null (creating nothing) while the session
     * does not yet hold the fields the order's non-nullable columns require.
     */
    private function upsertPendingOrder(SessionInterface $session, Cart $cart): ?Order
    {
        $email  = $session->get('checkout_email');
        $street = $session->get('checkout_shipping_address');
        $city   = $session->get('checkout_shipping_city');
        $postal = $session->get('checkout_shipping_postal');

        if ($cart->isEmpty() || !$email || !$street || !$city || !$postal) {
            return null;
        }

        $order = null;
        $existingId = $session->get('checkout_order_id');
        if ($existingId) {
            $candidate = $this->orderRepository->find($existingId);
            // Only reuse a still-pending row: once paid, the order is immutable.
            if ($candidate && $candidate->getStatus() === 'pending') {
                $order = $candidate;
            }
        }
        if (!$order) {
            $order = new Order();
        }

        $this->populateOrderFromSession($order, $session, $cart);

        // The PaymentIntent id on the order is the DB-level idempotency guard
        // (unique index); the order id in the PI metadata is the webhook's
        // fallback lookup. Set both.
        $piId = $session->get('checkout_pi_id');
        if ($piId) {
            $order->setStripePaymentIntentId($piId);
        }

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        $session->set('checkout_order_id', $order->getId());

        if ($piId) {
            try {
                $this->stripeClient->paymentIntents->update($piId, [
                    'metadata' => ['order_id' => (string) $order->getId()],
                ]);
            } catch (\Throwable $e) {
                $this->paymentLogger->warning('payment.intent.metadata_update_failed', [
                    'provider'       => 'stripe',
                    'payment_intent' => $piId,
                    'order_id'       => $order->getId(),
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        return $order;
    }

    /**
     * Finds the order a payment should finalize: the one pre-persisted for this
     * session, or — failing that — the one carrying the claimed PaymentIntent id.
     */
    private function findFinalizableOrder(SessionInterface $session, ?string $claimedIntentId): ?Order
    {
        $id = $session->get('checkout_order_id');
        if ($id) {
            $order = $this->orderRepository->find($id);
            if ($order) {
                return $order;
            }
        }

        if ($claimedIntentId) {
            return $this->orderRepository->findOneByStripePaymentIntentId($claimedIntentId);
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

    /**
     * Fills an order (new or still-pending) from the session and cart. Items and
     * tax lines are rebuilt from scratch each call so a second update-payment
     * (province or shipping change) does not duplicate them on the same order.
     */
    private function populateOrderFromSession(Order $order, SessionInterface $session, Cart $cart): void
    {
        $name             = $session->get('checkout_name', 'Client');
        $email            = $session->get('checkout_email');
        $phone            = $session->get('checkout_phone');
        $shippingStreet   = $session->get('checkout_shipping_address');
        $shippingCity     = $session->get('checkout_shipping_city');
        $shippingPostal   = $session->get('checkout_shipping_postal');
        $shippingProvince = $session->get('checkout_shipping_province');
        $billingSame      = $session->get('checkout_billing_same', true);
        $billingStreet    = $session->get('checkout_billing_address');
        $billingCity      = $session->get('checkout_billing_city');
        $billingPostal    = $session->get('checkout_billing_postal');
        $billingProvince  = $session->get('checkout_billing_province');

        // Use grand total if available (includes taxes + shipping), else fall back to cart subtotal
        $grandTotal = $session->get('checkout_grand_total');
        $total      = $grandTotal !== null ? (string) round((float) $grandTotal, 2) : $cart->getTotal();

        $subtotal          = $session->get('checkout_subtotal');
        $shippingAmount    = $session->get('checkout_shipping_amount');
        $shippingCarrier   = $session->get('checkout_shipping_carrier');
        $shippingMethod    = $session->get('checkout_shipping_method');
        $shippingReference = $session->get('checkout_shipping_reference');

        $order->setUser($this->getUser());
        // Snapshot the cart's currency, like the prices and addresses below:
        // changing the shop's currencies later must not reinterpret this order.
        $order->setCurrency($cart->getCurrency());
        $order->setStatus('pending');
        $order->setTotal($total);
        $order->setSubtotal($subtotal !== null ? (string) round((float) $subtotal, 2) : $cart->getTotal());
        $order->setShippingAmount($shippingAmount !== null ? (string) round((float) $shippingAmount, 2) : null);
        $order->setShippingMethodCarrier($shippingCarrier ?: null);
        $order->setShippingMethodName($shippingMethod ?: null);
        $order->setShippingMethodReference($shippingReference ?: null);

        // Rebuild the tax breakdown from the active market's engine, with the
        // same inputs updatePayment used, and snapshot the lines onto the order.
        foreach ($order->getTaxLines()->toArray() as $existingLine) {
            $order->removeTaxLine($existingLine);
        }
        $taxCountry = $session->get('checkout_shipping_country') ?: $this->marketContext->homeCountry();
        $quote = $this->taxEngineRegistry->active()->quote(
            (float) ($subtotal ?? $cart->getTotal()),
            $taxCountry,
            $shippingProvince ?: null,
        );
        $this->applyTaxQuote($order, $quote);

        $nameParts = explode(' ', trim($name), 2);
        $order->setCustomerFirstName($nameParts[0] ?? 'Client');
        $order->setCustomerLastName($nameParts[1] ?? '');
        $order->setCustomerEmail($email);
        $order->setCustomerPhone($phone);
        $order->setShippingStreet($shippingStreet);
        $order->setShippingCity($shippingCity);
        $order->setShippingPostalCode($shippingPostal);
        $order->setShippingProvince($shippingProvince ?: null);
        $order->setShippingCountry($session->get('checkout_shipping_country') ?: $this->marketContext->homeCountry());

        if ($billingSame || !$billingStreet) {
            $order->setBillingStreet($shippingStreet);
            $order->setBillingCity($shippingCity);
            $order->setBillingPostalCode($shippingPostal);
            $order->setBillingProvince($shippingProvince ?: null);
        } else {
            $order->setBillingStreet($billingStreet ?? $shippingStreet);
            $order->setBillingCity($billingCity ?? $shippingCity);
            $order->setBillingPostalCode($billingPostal ?? $shippingPostal);
            $order->setBillingProvince($billingProvince ?: $shippingProvince ?: null);
        }

        foreach ($order->getItems()->toArray() as $existingItem) {
            $order->removeItem($existingItem);
        }
        foreach ($cart->getItems() as $cartItem) {
            $orderItem = new OrderItem();
            $orderItem->setArticle($cartItem->getArticle());
            $orderItem->setQuantity($cartItem->getQuantity());
            $orderItem->setUnitPrice($cartItem->getUnitPrice());
            $orderItem->setSubtotal(number_format($cartItem->getSubtotal(), 2, '.', ''));
            $order->addItem($orderItem);
        }
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

    /**
     * Snapshots a tax engine's quote onto the order as OrderTaxLine rows.
     * The engine produced the neutral TaxLineData; this maps them to the
     * persisted entity and sets the total.
     */
    private function applyTaxQuote(Order $order, TaxQuote $quote): void
    {
        foreach ($quote->lines as $line) {
            $order->addTaxLine(new OrderTaxLine(
                code: $line->code,
                label: $line->label,
                rate: $line->rate,
                amount: $line->amount,
                jurisdiction: $line->jurisdiction,
            ));
        }

        $order->setTaxTotal($quote->total());
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
            'checkout_pi_id', 'checkout_order_id', 'checkout_subtotal', 'checkout_shipping_amount',
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
