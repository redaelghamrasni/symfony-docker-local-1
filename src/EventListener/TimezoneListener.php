<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class TimezoneListener implements EventSubscriberInterface
{
    private string $timezone;

    public function __construct(string $appTimezone = 'America/Toronto')
    {
        $this->timezone = $appTimezone;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 300]], // ← augmente à 300
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        date_default_timezone_set($this->timezone);
    }
}
