<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class LocaleSubscriber implements EventSubscriberInterface
{
    private string $defaultLocale;
    private array $supportedLocales;

    public function __construct(
        string $defaultLocale = 'en',
        array $supportedLocales = ['en', 'fr']
    ) {
        $this->defaultLocale = $defaultLocale;
        $this->supportedLocales = $supportedLocales;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 2000]],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        // Ignorer toutes les routes API
        if (str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        // Skip if not the main request
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$request->hasSession()) {
            return;
        }

        $session = $request->getSession();

        // Skip if locale is already set in route
        if ($request->attributes->has('_locale')) {
            $locale = $request->attributes->get('_locale');
            if (in_array($locale, $this->supportedLocales)) {
                $request->setLocale($locale);
                $session->set('_locale', $locale);
                return;
            }
        }

        // Get locale from session, or browser, or default
        $locale = $this->getPreferredLocale($request, $session);

        // Set the locale
        $request->setLocale($locale);
        $session->set('_locale', $locale);
    }

    private function getPreferredLocale(Request $request, SessionInterface $session): string
    {
        // 1. Check session preference
        $sessionLocale = $session->get('_locale');
        if ($sessionLocale && in_array($sessionLocale, $this->supportedLocales)) {
            return $sessionLocale;
        }

        // 2. Check browser Accept-Language header
        $browserLocale = $request->getPreferredLanguage($this->supportedLocales);
        if ($browserLocale) {
            return $browserLocale;
        }

        // 3. Fall back to default
        return $this->defaultLocale;
    }
}
