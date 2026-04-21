<?php

namespace App\Controller;

use App\Service\CartService;
use App\Entity\Cart;
use Stripe\StripeClient;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Attribute\Route;

class CheckoutController extends AbstractController
{
    public function __construct(
        private CartService $cartService,
        private StripeClient $stripeClient,
        private MailerInterface $mailer
    ) {}

    #[Route('/checkout', name: 'app_checkout_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $cart = $this->cartService->getCurrentCart();

        return $this->render('checkout/index.html.twig', [
            'cart' => $cart,
            'stripe_public_key' => $_ENV['STRIPE_PUBLIC_KEY'] ?? $_SERVER['STRIPE_PUBLIC_KEY'] ?? getenv('STRIPE_PUBLIC_KEY'),
        ]);
    }

    #[Route('/checkout/save-customer-info', name: 'app_checkout_save_customer_info', methods: ['POST'])]
    public function saveCustomerInfo(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?: [];
        $email = filter_var(trim($data['customer_email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $name = trim($data['customer_name'] ?? '');

        if (!$email) {
            return $this->json(['error' => 'Adresse e-mail invalide.'], 400);
        }

        $session = $request->getSession();
        $session->set('checkout_email', $email);
        $session->set('checkout_name', $name !== '' ? $name : 'Client');

        return $this->json(['ok' => true]);
    }

    #[Route('/checkout/create-payment-intent', name: 'app_checkout_create_payment_intent', methods: ['POST'])]
    public function createPaymentIntent(Request $request): JsonResponse
    {
        $cart = $this->cartService->getCurrentCart();

        if (count($cart->getItems()) === 0) {
            return $this->json(['error' => 'Votre panier est vide.'], 400);
        }

        $data = json_decode($request->getContent(), true) ?: [];
        $email = filter_var(trim($data['customer_email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $name = trim($data['customer_name'] ?? '');

        if ($email) {
            $session = $request->getSession();
            $session->set('checkout_email', $email);
            $session->set('checkout_name', $name !== '' ? $name : 'Client');
        }

        $amount = (int) round((float) $cart->getTotal() * 100);
        if ($amount <= 0) {
            return $this->json(['error' => 'Montant de paiement invalide.'], 400);
        }

        $paymentIntent = $this->stripeClient->paymentIntents->create([
            'amount' => $amount,
            'currency' => 'usd',
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => [
                'cart_id' => $cart->getId(),
                'customer_email' => $email ?: '',
                'customer_name' => $name ?: '',
            ],
        ]);

        return $this->json([
            'clientSecret' => $paymentIntent->client_secret,
            'publishableKey' => $_ENV['STRIPE_PUBLIC_KEY'] ?? $_SERVER['STRIPE_PUBLIC_KEY'] ?? getenv('STRIPE_PUBLIC_KEY'),
        ]);
    }

    #[Route('/checkout/success', name: 'app_checkout_success', methods: ['GET'])]
    public function success(Request $request): Response
    {
        $session = $request->getSession();
        $checkoutEmail = $session->get('checkout_email');
        $checkoutName = $session->get('checkout_name', 'Client');

        if ($request->query->get('redirect_status') === 'succeeded') {
            if ($checkoutEmail) {
                $cart = $this->cartService->getCurrentCart();
                $this->sendOrderConfirmationEmail($checkoutEmail, $checkoutName, $cart);
            }

            $session->remove('checkout_email');
            $session->remove('checkout_name');
            $this->cartService->clear();
        }

        return $this->render('checkout/success.html.twig');
    }

    private function sendOrderConfirmationEmail(string $email, string $name, Cart $cart): void
    {
        $message = (new TemplatedEmail())
            ->from(new Address('no-reply@monapp.local', 'MonApp'))
            ->to($email)
            ->subject('Confirmation de votre commande')
            ->htmlTemplate('emails/order_confirmation.html.twig')
            ->context([
                'name' => $name,
                'cart' => $cart,
            ]);

        $this->mailer->send($message);
    }
}
