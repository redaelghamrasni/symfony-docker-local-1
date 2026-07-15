<?php

namespace App\Controller;

use App\Entity\Address;
use App\Service\CartService;
use App\Service\PayPalService;
use App\Service\SettingService;
use App\Service\TaxService;
use App\Entity\Cart;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\User;
use App\Repository\AddressRepository;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Stripe\StripeClient;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
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
        private AddressRepository $addressRepository,
        private OrderRepository $orderRepository,
        private LocaleSwitcher $localeSwitcher,
        private TranslatorInterface $translator
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

        $session = $request->getSession();
        $session->set('checkout_email', $email);
        $session->set('checkout_name', $name !== '' ? $name : 'Client');
        $session->set('checkout_phone', trim($data['customer_phone'] ?? ''));
        $session->set('checkout_shipping_address',  trim($data['checkout_shipping_address'] ?? ''));
        $session->set('checkout_shipping_city',     trim($data['checkout_shipping_city'] ?? ''));
        $session->set('checkout_shipping_postal',   trim($data['checkout_shipping_postal'] ?? ''));
        $session->set('checkout_shipping_province', trim($data['checkout_shipping_province'] ?? ''));
        $session->set('checkout_billing_same',    (bool)($data['checkout_billing_same'] ?? true));
        $session->set('checkout_billing_address',   trim($data['checkout_billing_address'] ?? ''));
        $session->set('checkout_billing_city',      trim($data['checkout_billing_city'] ?? ''));
        $session->set('checkout_billing_postal',    trim($data['checkout_billing_postal'] ?? ''));
        $session->set('checkout_billing_province',  trim($data['checkout_billing_province'] ?? ''));

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
            $paymentIntent = $this->stripeClient->paymentIntents->create([
                'amount'                  => $amount,
                'currency'                => 'cad',
                'automatic_payment_methods' => ['enabled' => true],
                'metadata' => [
                    'cart_id'        => $cart->getId(),
                    'customer_email' => $email ?: '',
                    'customer_name'  => $name  ?: '',
                ],
            ]);
        } catch (\Throwable) {
            return $this->json(['error' => 'Erreur de connexion au serveur de paiement.'], 502);
        }

        // Store PI ID so we can update the amount later
        $request->getSession()->set('checkout_pi_id', $paymentIntent->id);

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

        $cart     = $this->cartService->getCurrentCart();
        $subtotal = (float) $cart->getTotal();

        [$gst, $pst, $hst] = $this->calculateTaxes($province, $subtotal);
        $taxes     = $gst + $pst + $hst;
        $grandTotal = $subtotal + $taxes + $shipping;

        // Persist amounts to session
        $session = $request->getSession();
        $session->set('checkout_subtotal',         $subtotal);
        $session->set('checkout_shipping_amount',  $shipping);
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
            } catch (\Throwable) {
                // Non-fatal; user will see correct total on Stripe form
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

            if (!$cart->isEmpty() && $checkoutEmail && $shippingStreet && $shippingCity && $shippingPostal) {
                $order = $this->buildOrderFromSession($session, $cart);
                $this->capturePaymentInfo($order, $request->query->get('payment_intent'));
                $this->entityManager->persist($order);
                $this->saveAddressFromOrder($order);
                $this->entityManager->flush();
                $this->sendOrderConfirmationEmail($order);
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
            return $this->json(['id' => $result['id']]);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 500);
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
            return $this->json(['error' => $e->getMessage()], 500);
        }

        if (($capture['status'] ?? '') !== 'COMPLETED') {
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

            $this->sendOrderConfirmationEmail($order);

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
        } catch (\Throwable) {
            return $this->json(['error' => 'Erreur lors du chargement des tarifs.'], 502);
        }

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
        $taxGst         = $session->get('checkout_tax_gst');
        $taxPst         = $session->get('checkout_tax_pst');
        $taxHst         = $session->get('checkout_tax_hst');

        $order = new Order();
        $order->setUser($this->getUser());
        $order->setStatus('pending');
        $order->setTotal($total);
        $order->setSubtotal($subtotal !== null ? (string) round((float) $subtotal, 2) : $cart->getTotal());
        $order->setShippingAmount($shippingAmount !== null ? (string) round((float) $shippingAmount, 2) : null);
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
        } catch (\Throwable) {
            $order->setPaymentMethod('card');
            $order->setStripePaymentIntentId($paymentIntentId);
        }
    }

    private function sendOrderConfirmationEmail(Order $order): void
    {
        // Send in the customer's preferred language; default to French when unavailable (e.g. guest checkout)
        $locale = $order->getUser()?->getLocale() ?? 'fr';

        $this->localeSwitcher->runWithLocale($locale, function () use ($order): void {
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
