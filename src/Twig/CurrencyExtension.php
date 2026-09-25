<?php

namespace App\Twig;

use App\Entity\Article;
use App\Service\CurrencyService;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Currency helpers for templates.
 *
 * Templates previously hardcoded "{{ price|number_format(2, ',', ' ') }} $",
 * which silently means "assume Canadian dollars". These helpers ask the
 * service instead, so one change of currency updates every price on the page.
 */
class CurrencyExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(private readonly CurrencyService $currencyService)
    {
    }

    public function getGlobals(): array
    {
        return [
            // Available to every template: the selector in the top bar and any
            // price display need these on essentially every page.
            'currentCurrency'    => $this->currencyService->resolveForVisitor(),
            'enabledCurrencies'  => $this->currencyService->enabled(),
        ];
    }

    public function getFilters(): array
    {
        return [
            // {{ 377.99|money }} -> "$ 377,99"
            new TwigFilter('money', [$this, 'money']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            // {{ article_price(article) }} -> price in the visitor's currency,
            // promotion applied, hand-set price honoured.
            new TwigFunction('article_price', [$this, 'articlePrice']),
            new TwigFunction('article_base_price', [$this, 'articleBasePrice']),
        ];
    }

    public function money(float|string|null $amount, ?string $code = null): string
    {
        return $this->currencyService->format((float) ($amount ?? 0), $code);
    }

    public function articlePrice(Article $article, ?string $code = null): float
    {
        return $this->currencyService->effectivePriceFor($article, $code ?? $this->currencyService->resolveForVisitor());
    }

    /** Pre-promotion price, for showing a struck-through original. */
    public function articleBasePrice(Article $article, ?string $code = null): float
    {
        return $this->currencyService->priceFor($article, $code ?? $this->currencyService->resolveForVisitor());
    }
}
