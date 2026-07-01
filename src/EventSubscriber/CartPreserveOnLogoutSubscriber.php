<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

class CartPreserveOnLogoutSubscriber implements EventSubscriberInterface
{
    private ?int $savedCartId = null;

    public static function getSubscribedEvents(): array
    {
        return [
            LogoutEvent::class => [
                ['saveCartId', 100],
                ['restoreCartId', -100],
            ],
        ];
    }

    public function saveCartId(LogoutEvent $event): void
    {
        $session = $event->getRequest()->getSession();
        $cartId = $session->get('cart_id');
        if ($cartId) {
            $this->savedCartId = (int) $cartId;
        }
    }

    public function restoreCartId(LogoutEvent $event): void
    {
        if ($this->savedCartId !== null) {
            $event->getRequest()->getSession()->set('cart_id', $this->savedCartId);
            $this->savedCartId = null;
        }
    }
}
