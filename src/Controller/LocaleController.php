<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\RedirectResponse;

class LocaleController extends AbstractController
{
    #[Route('/switch-locale/{locale}', name: 'app_switch_locale', requirements: ['locale' => 'en|fr'])]
    public function switchLocale(string $locale, Request $request): Response
    {
        $request->getSession()->set('_locale', $locale);

        $referer = $request->headers->get('referer');

        if ($referer) {
            // Extrait le path depuis l'URL complète
            $parsedUrl = parse_url($referer);
            $path = $parsedUrl['path'] ?? '/';

            // Retire le préfixe de locale existant (/en/ ou /fr/)
            $path = preg_replace('#^/(en|fr)(/|$)#', '/', $path);

            // Ajoute la nouvelle locale en préfixe
            $path = '/' . $locale . '/' . ltrim($path, '/');

            // Reconstruit l'URL proprement
            $newUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
            if (isset($parsedUrl['port'])) {
                $newUrl .= ':' . $parsedUrl['port'];
            }
            $newUrl .= $path;
            if (isset($parsedUrl['query'])) {
                $newUrl .= '?' . $parsedUrl['query'];
            }
        } else {
            $newUrl = $this->generateUrl('app_home', ['_locale' => $locale]);
        }

        return new RedirectResponse($newUrl);
    }
}
