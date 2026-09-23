<?php

namespace App\Controller;

use App\Entity\Address;
use App\Service\CartService;
use App\Service\PayPalService;
use App\Service\SettingService;
use App\Service\TaxService;
use App\Service\StripeCustomerService;
use App\Entity\Cart;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\User;
use App\Message\ReindexEntityMessage;
use App\Repository\AddressRepository;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\StripeClient;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Address as EmailAddress;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\ShippingService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

class CheckoutController extends AbstractController
{
    // Canadian tax rates by province code
    private const TAX_RATES = [
        'AB' => ['gst' => 0.05, 'pst' => 0.00],
        'BC' => ['gst' => 0.05, 'pst' => 0.07],
        'MB' => ['gst' => 0.05, 'pst' => 0.07],
        'NB' => ['gst' => 0.00, 'pst' => 0.00, 'hst' => 0.15],
        'NL' => ['gst' => 0.00, 'pst' => 0.00, 'hst' => 0.15],
        'NS' => ['gst' => 0.00, 'pst' => 0.00, 'hst' => 0.15],
        'NT' => ['gst' => 0.05, 'pst' => 0.00],
        'NU' => ['gst' => 0.05, 'pst' => 0.00],
        'ON' => ['gst' => 0.00, 'pst' => 0.00, 'hst' => 0.13],
        'PE' => ['gst' => 0.00, 'pst' => 0.00, 'hst' => 0.15],
        'QC' => ['gst' => 0.05, 'pst' => 0.09975],
        'SK' => ['gst' => 0.05, 'pst' => 0.06],
        'YT' => ['gst' => 0.05, 'pst' => 0.00],
    ];

    public function __construct(
        private CartService $cartService,
        private StripeClient $stripeClient,
        private PayPalService $payPalService,
        private MailerInterface $mailer,
        private EntityManagerInterface $entityManager,
        private ShippingService $shippingService,
        private SettingService $settingService,
        private StripeCustomerService $stripeCustomerService,
        private AddressRepository $addressRepository,
        private OrderRepository $orderRepository,
        private LocaleSwitcher $localeSwitcher,
        private TranslatorInterface $translator,
        private MessageBusInterface $messageBus,
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

        $session = $request->getSession();
        $session->set('checkout_email', $email);
        $session->set('checkout_name', $name !== '' ? $name : 'Client');
        $session->set('checkout_phone', $phone);
        $session->set('checkout_shipping_address',  $shippingAddress);
        $session->set('checkout_shipping_city',     $shippingCity);
        $session->set('checkout_shipping_postal',   $shippingPostal);
        $session->set('checkout_shipping_province', $shippingProvince);
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

        $subtotal = (float) $cart->getTotal();
        $amount   = (int) round($subtotal * 100);
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
                'currency'                  => 'cad',
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
                'amount_cents' => $amount,
                'currency'     => 'cad',
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
            'amount_cents'   => $amount,
            'currency'       => 'cad',
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
        $subtotal = (float) $cart->getTotal();

        [$gst, $pst, $hst] = $this->calculateTaxes($province, $subtotal);
        $taxes     = $gst + $pst + $hst;
        $grandTotal = $subtotal + $taxes + $shipping;

        // Persist amounts to session
        $session = $request->getSession();
        $session->set('checkout_subtotal',         $subtotal);
        $session->set('checkout_shipping_amount',  $shipping);
        $session->set('checkout_shipping_carrier',   $shippingCarrier !== '' ? $shippingCarrier : null);
        $session->set('checkout_shipping_method',    $shippingMethod !== '' ? $shippingMethod : null);
        $session->set('checkout_shipping_reference',  $shippingReference !== '' ? $shippingReference : null);
        $session->set('checkout_tax_gst',          $gst);
        $session->set('checkout_tax_pst',          $pst);
        $session->set('checkout_tax_hst',          $hst);
        $session->set('checkout_grand_total',      $grandTotal);

        // Update Stripe PaymentIntent amount
        $piId = $session->get('checkout_pi_id');
        if ($piId) {
            try {
                $this->stripeClient->paymentIntents->update($piId, [
                    'amount' => (int) round($grandTotal * 100),
                ]);
            } catch (\Throwable $e) {
                // Non-fatal for the customer (Stripe's form shows the right
                // total), but it means our PaymentIntent and our cart disagree
                // on the amount — worth seeing if it starts happening often.
                $this->paymentLogger->warning('payment.intent.amount_update_failed', [
                    'provider'       => 'stripe',
                    'payment_intent' => $piId,
                    'amount_cents'   => (int) round($grandTotal * 100),
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        return $this->json([
            'ok'          => true,
            'subtotal'    => $subtotal,
            'gst'         => $gst,
            'pst'         => $pst,
            'hst'         => $hst,
            'shipping'    => $shipping,
            'grand_total' => $grandTotal,
        ]);
    }

    #[Route('/checkout/success', name: 'app_checkout_success', methods: ['GET'])]
    public function success(Request $request): Response
    {
        $session       = $request->getSession();
        $checkoutEmail = $session->get('checkout_email');
        $checkoutName  = $session->get('checkout_name', 'Client');

        if ($request->query->get('redirect_status') === 'succeeded') {
            $cart            = $this->cartService->getCurrentCart();
            $shippingStreet  = $session->get('checkout_shipping_address');
            $shippingCity    = $session->get('checkout_shipping_city');
            $shippingPostal  = $session->get('checkout_shipping_postal');

            $claimedIntentId = $request->query->get('payment_intent');
            $sessionIntentId = $session->get('checkout_pi_id');

            if (!$cart->isEmpty() && $checkoutEmail && $shippingStreet && $shippingCity && $shippingPostal) {
                $order = $this->buildOrderFromSession($session, $cart);
                $this->capturePaymentInfo($order, $claimedIntentId);
                $this->entityManager->persist($order);
                $this->saveAddressFromOrder($order);
                $this->entityManager->flush();
                $this->messageBus->dispatch(new ReindexEntityMessage('order', $order->getId()));
                $this->sendOrderConfirmationEmail($order);

                // The business event. Written on the success path on purpose:
                // without it, a completed order leaves no trace in the logs and
                // orders/conversion cannot be counted from them.
                $this->checkoutLogger->info('checkout.order.placed', [
                    'order_id'       => $order->getId(),
                    'cart_id'        => $cart->getId(),
                    'provider'       => 'stripe',
                    'payment_intent' => $claimedIntentId,
                    'total'          => $order->getTotal(),
                    'currency'       => 'CAD',
                    'item_count'     => count($order->getItems()),
                    'province'       => $order->getShippingProvince(),
                    'is_guest'       => $this->getUser() === null,
                ]);

                $this->verifyStripePayment($order, $claimedIntentId, $sessionIntentId);
                $this->entityManager->flush();
            }

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

        $session    = $request->getSession();
        $grandTotal = (float) ($session->get('checkout_grand_total') ?: $cart->getTotal());

        try {
            $result = $this->payPalService->createOrder($grandTotal);

            $this->paymentLogger->info('payment.paypal.order_created', [
                'provider'        => 'paypal',
                'paypal_order_id' => $result['id'] ?? null,
                'cart_id'         => $cart->getId(),
                'total'           => $grandTotal,
                'currency'        => 'CAD',
            ]);

            return $this->json(['id' => $result['id']]);
        } catch (\Throwable $e) {
            $this->paymentLogger->error('payment.paypal.create_failed', [
                'provider'    => 'paypal',
                'cart_id'     => $cart->getId(),
                'total'       => $grandTotal,
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

        $session        = $request->getSession();
        $cart           = $this->cartService->getCurrentCart();
        $email          = $session->get('checkout_email');
        $name           = $session->get('checkout_name', 'Client');
        $shippingStreet = $session->get('checkout_shipping_address');
        $shippingCity   = $session->get('checkout_shipping_city');
        $shippingPostal = $session->get('checkout_shipping_postal');

        if (!$cart->isEmpty() && $email && $shippingStreet && $shippingCity && $shippingPostal) {
            $order = $this->buildOrderFromSession($session, $cart);
            $order->setPaymentMethod('paypal');
            $order->setStripePaymentIntentId(null);

            $payerEmail = $capture['payment_source']['paypal']['email_address']
                ?? $capture['payer']['email_address']
                ?? null;
            if ($payerEmail) {
                $order->setPaymentBrand($payerEmail);
            }

            $this->entityManager->persist($order);
            $this->saveAddressFromOrder($order);
            $this->entityManager->flush();
            $this->messageBus->dispatch(new ReindexEntityMessage('order', $order->getId()));

            $this->sendOrderConfirmationEmail($order);

            // Same event name and shape as the Stripe path, so "how many orders
            // were placed" is one query rather than two.
            $this->checkoutLogger->info('checkout.order.placed', [
                'order_id'        => $order->getId(),
                'cart_id'         => $cart->getId(),
                'provider'        => 'paypal',
                'paypal_order_id' => $paypalOrderId,
                'total'           => $order->getTotal(),
                'currency'        => 'CAD',
                'item_count'      => count($order->getItems()),
                'province'        => $order->getShippingProvince(),
                'is_guest'        => $this->getUser() === null,
            ]);

            // Same flag as the Stripe path: PayPal said COMPLETED, but confirm
            // it captured the amount we actually charged.
            $capturedAmount = $capture['purchase_units'][0]['payments']['captures'][0]['amount']['value'] ?? null;

            if ($capturedAmount === null) {
                $order->setPaymentVerified(false);
                $order->setPaymentVerificationIssue('amount_missing');

                $this->paymentLogger->error('payment.verification_failed', [
                    'order_id'        => $order->getId(),
                    'provider'        => 'paypal',
                    'issue'           => 'amount_missing',
                    'paypal_order_id' => $paypalOrderId,
                    'action'          => 'order stored but flagged; do not fulfil until reviewed',
                ]);
            } elseif (abs((float) $capturedAmount - (float) $order->getTotal()) > 0.01) {
                $order->setPaymentVerified(false);
                $order->setPaymentVerificationIssue('amount_mismatch');

                $this->paymentLogger->error('payment.verification_failed', [
                    'order_id'        => $order->getId(),
                    'provider'        => 'paypal',
                    'issue'           => 'amount_mismatch',
                    'paypal_order_id' => $paypalOrderId,
                    'captured'        => $capturedAmount,
                    'expected'        => $order->getTotal(),
                    'action'          => 'order stored but flagged; do not fulfil until reviewed',
                ]);
            } else {
                $order->setPaymentVerified(true);

                $this->paymentLogger->info('payment.verified', [
                    'order_id'        => $order->getId(),
                    'provider'        => 'paypal',
                    'paypal_order_id' => $paypalOrderId,
                    'amount'          => $capturedAmount,
                    'currency'        => 'CAD',
                ]);
            }

            $this->entityManager->flush();

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
        $totalItems = 0;
        foreach ($cart->getItems() as $item) {
            $totalItems += $item->getQuantity();
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
                'currency'  => 'CAD',
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
                    'state'   => $data['province'] ?? 'QC',
                    'country' => $data['country'] ?? 'CA',
                    'email'   => $data['email'] ?? '',
                ],
                [
                    'weight' => max(0.5, $totalItems * 0.5),
                    'length' => '30',
                    'width'  => '20',
                    'height' => '15',
                ]
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

    private function buildOrderFromSession(\Symfony\Component\HttpFoundation\Session\SessionInterface $session, Cart $cart): Order
    {
        $name            = $session->get('checkout_name', 'Client');
        $email           = $session->get('checkout_email');
        $phone           = $session->get('checkout_phone');
        $shippingStreet  = $session->get('checkout_shipping_address');
        $shippingCity    = $session->get('checkout_shipping_city');
        $shippingPostal  = $session->get('checkout_shipping_postal');
        $shippingProvince = $session->get('checkout_shipping_province');
        $billingSame     = $session->get('checkout_billing_same', true);
        $billingStreet   = $session->get('checkout_billing_address');
        $billingCity     = $session->get('checkout_billing_city');
        $billingPostal   = $session->get('checkout_billing_postal');
        $billingProvince = $session->get('checkout_billing_province');

        // Use grand total if available (includes taxes + shipping), else fall back to cart subtotal
        $grandTotal = $session->get('checkout_grand_total');
        $total      = $grandTotal !== null ? (string) round((float)$grandTotal, 2) : $cart->getTotal();

        $subtotal       = $session->get('checkout_subtotal');
        $shippingAmount = $session->get('checkout_shipping_amount');
        $shippingCarrier   = $session->get('checkout_shipping_carrier');
        $shippingMethod    = $session->get('checkout_shipping_method');
        $shippingReference = $session->get('checkout_shipping_reference');
        $taxGst         = $session->get('checkout_tax_gst');
        $taxPst         = $session->get('checkout_tax_pst');
        $taxHst         = $session->get('checkout_tax_hst');

        $order = new Order();
        $order->setUser($this->getUser());
        $order->setStatus('pending');
        $order->setTotal($total);
        $order->setSubtotal($subtotal !== null ? (string) round((float) $subtotal, 2) : $cart->getTotal());
        $order->setShippingAmount($shippingAmount !== null ? (string) round((float) $shippingAmount, 2) : null);
        $order->setShippingMethodCarrier($shippingCarrier ?: null);
        $order->setShippingMethodName($shippingMethod ?: null);
        $order->setShippingMethodReference($shippingReference ?: null);
        $order->setTaxGst($taxGst !== null ? (string) round((float) $taxGst, 2) : '0.00');
        $order->setTaxPst($taxPst !== null ? (string) round((float) $taxPst, 2) : '0.00');
        $order->setTaxHst($taxHst !== null ? (string) round((float) $taxHst, 2) : '0.00');

        $nameParts = explode(' ', trim($name), 2);
        $order->setCustomerFirstName($nameParts[0] ?? 'Client');
        $order->setCustomerLastName($nameParts[1] ?? '');
        $order->setCustomerEmail($email);
        $order->setCustomerPhone($phone);
        $order->setShippingStreet($shippingStreet);
        $order->setShippingCity($shippingCity);
        $order->setShippingPostalCode($shippingPostal);
        $order->setShippingProvince($shippingProvince ?: null);

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

        foreach ($cart->getItems() as $cartItem) {
            $orderItem = new OrderItem();
            $orderItem->setArticle($cartItem->getArticle());
            $orderItem->setQuantity($cartItem->getQuantity());
            $orderItem->setUnitPrice($cartItem->getUnitPrice());
            $orderItem->setSubtotal(number_format($cartItem->getSubtotal(), 2, '.', ''));
            $order->addItem($orderItem);
        }

        return $order;
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
     * Confirms with Stripe that the payment behind this order actually happened,
     * and flags the order when it cannot be confirmed.
     *
     * The order is still stored either way. Refusing outright would mean a real
     * customer whose payment succeeded but whose verification call failed (a
     * Stripe outage, a recycled session) ends up charged with no order — the
     * one outcome that is worse than a suspicious row in the database. A flagged
     * order is visible, recoverable and safe as long as nothing ships before
     * someone looks at it.
     *
     * Three things are checked, cheapest first:
     *   1. the payment intent matches the one THIS session created — no network
     *      call, and on its own it defeats a hand-typed /checkout/success;
     *   2. Stripe reports the intent as succeeded;
     *   3. the amount Stripe captured matches the order total.
     */
    private function verifyStripePayment(Order $order, ?string $claimedIntentId, ?string $sessionIntentId): void
    {
        $fail = function (string $issue, array $context = []) use ($order): void {
            $order->setPaymentVerified(false);
            $order->setPaymentVerificationIssue($issue);

            $this->paymentLogger->error('payment.verification_failed', array_merge([
                'order_id'    => $order->getId(),
                'provider'    => 'stripe',
                'issue'       => $issue,
                'total'       => $order->getTotal(),
                'action'      => 'order stored but flagged; do not fulfil until reviewed',
            ], $context));
        };

        if ($claimedIntentId === null || $sessionIntentId === null || $claimedIntentId !== $sessionIntentId) {
            $fail('intent_mismatch', [
                'claimed_intent' => $claimedIntentId,
                'session_intent' => $sessionIntentId,
            ]);

            return;
        }

        try {
            $intent = $this->stripeClient->paymentIntents->retrieve($claimedIntentId, []);
        } catch (\Throwable $e) {
            $fail('verification_unavailable', [
                'payment_intent' => $claimedIntentId,
                'error'          => $e->getMessage(),
            ]);

            return;
        }

        if (($intent->status ?? null) !== 'succeeded') {
            $fail('intent_not_succeeded', [
                'payment_intent' => $claimedIntentId,
                'stripe_status'  => $intent->status ?? null,
            ]);

            return;
        }

        // Stripe works in cents; the order total is a decimal string.
        $expectedCents = (int) round(((float) $order->getTotal()) * 100);
        $receivedCents = (int) ($intent->amount_received ?? 0);

        if ($receivedCents !== $expectedCents) {
            $fail('amount_mismatch', [
                'payment_intent' => $claimedIntentId,
                'received_cents' => $receivedCents,
                'expected_cents' => $expectedCents,
            ]);

            return;
        }

        $order->setPaymentVerified(true);
        $order->setPaymentVerificationIssue(null);

        $this->paymentLogger->info('payment.verified', [
            'order_id'       => $order->getId(),
            'provider'       => 'stripe',
            'payment_intent' => $claimedIntentId,
            'amount_cents'   => $receivedCents,
            'currency'       => 'CAD',
        ]);
    }

    private function capturePaymentInfo(Order $order, ?string $paymentIntentId): void
    {
        if (!$paymentIntentId) {
            $order->setPaymentMethod('card');
            return;
        }
        try {
            $pi = $this->stripeClient->paymentIntents->retrieve(
                $paymentIntentId,
                ['expand' => ['payment_method']]
            );
            $order->setStripePaymentIntentId($paymentIntentId);
            $pm = $pi->payment_method;
            if ($pm !== null) {
                $order->setPaymentMethod($pm->type ?? 'card');
                $card = $pm->card ?? null;
                if ($card !== null) {
                    $order->setPaymentBrand($card->brand ?? null);
                    $order->setPaymentLast4($card->last4 ?? null);
                }
            } else {
                $order->setPaymentMethod(($pi->payment_method_types)[0] ?? 'card');
            }
        } catch (\Throwable $e) {
            // The order is still created, but with a generic 'card' method and
            // no brand or last4 — a silent downgrade of what the customer and
            // support will later see on the order. Record that it happened.
            $order->setPaymentMethod('card');
            $order->setStripePaymentIntentId($paymentIntentId);

            $this->paymentLogger->warning('payment.details_capture_failed', [
                'provider'       => 'stripe',
                'payment_intent' => $paymentIntentId,
                'consequence'    => 'order stored without card brand or last4',
                'error'          => $e->getMessage(),
            ]);
        }
    }

    private function sendOrderConfirmationEmail(Order $order): void
    {
        // Send in the customer's preferred language; default to French when unavailable (e.g. guest checkout)
        $locale = $order->getUser()?->getLocale() ?? 'fr';

        $this->localeSwitcher->runWithLocale($locale, function () use ($order, $locale): void {
            $message = (new TemplatedEmail())
                ->from(new EmailAddress('no-reply@monapp.local', 'MonApp'))
                ->to($order->getCustomerEmail())
                ->subject($this->translator->trans('email.order_confirmation.subject'))
                ->htmlTemplate('emails/order_confirmation.html.twig')
                ->context(['order' => $order, 'locale' => $locale]);

            $this->mailer->send($message);
        });
    }

    private function clearCheckoutSession(\Symfony\Component\HttpFoundation\Session\SessionInterface $session): void
    {
        foreach ([
            'checkout_email', 'checkout_name', 'checkout_phone',
            'checkout_shipping_address', 'checkout_shipping_city',
            'checkout_shipping_postal', 'checkout_shipping_province',
            'checkout_billing_same', 'checkout_billing_address',
            'checkout_billing_city', 'checkout_billing_postal', 'checkout_billing_province',
            'checkout_pi_id', 'checkout_subtotal', 'checkout_shipping_amount',
            'checkout_shipping_carrier', 'checkout_shipping_method', 'checkout_shipping_reference',
            'checkout_tax_gst', 'checkout_tax_pst', 'checkout_tax_hst', 'checkout_grand_total',
        ] as $key) {
            $session->remove($key);
        }
    }

    private function calculateTaxes(string $province, float $subtotal): array
    {
        $rates = self::TAX_RATES[$province] ?? [];
        $gst   = round($subtotal * ($rates['gst'] ?? 0.0), 2);
        $pst   = round($subtotal * ($rates['pst'] ?? 0.0), 2);
        $hst   = round($subtotal * ($rates['hst'] ?? 0.0), 2);
        return [$gst, $pst, $hst];
    }

    #[Route('/checkout/tax', name: 'app_checkout_tax', methods: ['POST'])]
    public function calculateTaxApi(Request $request, TaxService $taxService): JsonResponse
    {
        $data     = json_decode($request->getContent(), true) ?? [];
        $province = $data['province'] ?? 'QC';
        $cart     = $this->cartService->getCurrentCart();
        $tax = $taxService->calculateTax((float) $cart->getTotal(), $province);

        return $this->json($tax);
    }
}
