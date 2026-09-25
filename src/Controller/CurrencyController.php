<?php

namespace App\Controller;

use App\Service\CartService;
use App\Service\CurrencyService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Storefront currency switching.
 *
 * The submitted code is validated against the enabled currencies before
 * anything happens — an unknown or disabled code leaves the visitor on their
 * current currency rather than being trusted. The chosen currency is stored in
 * the session and on the cart, never carried on each request.
 */
class CurrencyController extends AbstractController
{
    public function __construct(
        private readonly CurrencyService $currencyService,
        private readonly CartService $cartService,
        private readonly LoggerInterface $checkoutLogger,
    ) {
    }

    #[Route('/currency', name: 'app_currency_switch', methods: ['POST'])]
    public function switch(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('switch_currency', (string) $request->request->get('_token'))) {
            return $this->redirectToPrevious($request);
        }

        $code = (string) $request->request->get('code', '');

        // An unknown or disabled code leaves the visitor on the currency they
        // are already using. Normalising it to the default instead would let a
        // malformed request silently reset someone's chosen currency — and
        // re-price their cart — which is worse than ignoring the request.
        if (!$this->currencyService->isSupported($code)) {
            $this->checkoutLogger->warning('checkout.currency.rejected', [
                'requested' => strtoupper($code),
                'reason'    => 'not an enabled currency',
            ]);

            return $this->redirectToPrevious($request);
        }

        $applied = $this->currencyService->rememberChoice($code);

        // Re-price whatever is already in the cart so the displayed prices and
        // the amount that will be charged cannot disagree.
        $this->cartService->switchCurrency($applied);

        $this->checkoutLogger->info('checkout.currency.switched', [
            'requested' => strtoupper($code),
            'applied'   => $applied,
        ]);

        return $this->redirectToPrevious($request);
    }

    /**
     * Back where the visitor was. Only same-origin paths are followed, so the
     * referer cannot be used to bounce someone off-site.
     */
    private function redirectToPrevious(Request $request): Response
    {
        $referer = (string) $request->headers->get('referer', '');

        if ($referer !== '') {
            $path = parse_url($referer, PHP_URL_PATH);
            $host = parse_url($referer, PHP_URL_HOST);

            if (is_string($path) && $path !== '' && $host === $request->getHost()) {
                $query = parse_url($referer, PHP_URL_QUERY);

                return $this->redirect($path . ($query ? '?' . $query : ''));
            }
        }

        return $this->redirectToRoute('app_article_list');
    }
}
