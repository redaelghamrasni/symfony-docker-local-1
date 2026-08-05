<?php

namespace App\Service;

use App\Entity\Order;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

class OrderNotificationMailer
{
    // Statuses announced to the customer by email. "pending" is already covered by the
    // order-confirmation email sent at checkout; "cancelled" isn't part of this notification yet.
    private const NOTIFIABLE_STATUSES = ['in_progress', 'shipped', 'completed'];

    public function __construct(
        private MailerInterface $mailer,
        private TranslatorInterface $translator,
        private LocaleSwitcher $localeSwitcher,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function notifyStatusChange(Order $order, string $previousStatus): void
    {
        $status = $order->getStatus();

        if ($status === $previousStatus || !in_array($status, self::NOTIFIABLE_STATUSES, true)) {
            return;
        }

        // Send in the customer's preferred language; default to French when unavailable (e.g. guest orders)
        $locale = $order->getUser()?->getLocale() ?? 'fr';

        $this->localeSwitcher->runWithLocale($locale, function () use ($order, $status, $locale): void {
            $orderUrl = $order->getUser()
                ? $this->urlGenerator->generate('app_profile_order_show', ['id' => $order->getId()], UrlGeneratorInterface::ABSOLUTE_URL)
                : null;

            $message = (new TemplatedEmail())
                ->from(new Address('no-reply@monapp.local', 'MonApp'))
                ->to($order->getCustomerEmail())
                ->subject($this->translator->trans('email.order_status_changed.subject_' . $status, ['%id%' => $order->getId()]))
                ->htmlTemplate('emails/order_status_changed.html.twig')
                ->context(['order' => $order, 'locale' => $locale, 'orderUrl' => $orderUrl]);

            $this->mailer->send($message);
        });
    }
}
