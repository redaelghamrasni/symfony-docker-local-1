<?php

namespace App\Service;

use App\Entity\Article;
use App\Entity\Currency;
use App\Repository\CurrencyRepository;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The single authority on currency: which ones exist, which one applies to the
 * visitor, and what an article costs in it.
 *
 * Currency is never taken from a request payload. A client-supplied currency
 * would let someone pay the same integer amount in a cheaper unit, so the
 * selector below only accepts a code that matches an enabled currency, and the
 * value lives in the session and on the cart rather than travelling with each
 * request.
 *
 * ── Where the future market/country logic plugs in ──────────────────────────
 * resolveForVisitor() is the only place that decides which currency a visitor
 * sees. When countries arrive — shipping feasibility, stock location, tax
 * rules — that mapping belongs here: a country resolver picks the currency,
 * and everything downstream (cart, checkout, payment, order) keeps reading the
 * cart's currency exactly as it does today. Nothing else needs to change.
 */
class CurrencyService
{
    /**
     * Currencies with no minor unit: providers expect the amount as-is rather
     * than multiplied by 100. Getting this wrong charges 100x or 1/100th.
     *
     * @see https://docs.stripe.com/currencies#zero-decimal
     */
    private const ZERO_DECIMAL = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA',
        'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    public const SESSION_KEY = 'display_currency';

    /** Request-scoped memo: these are read repeatedly while rendering a page. */
    private ?array $enabledCache = null;
    private ?Currency $defaultCache = null;
    /** All currencies indexed by code, for formatting historical amounts. */
    private ?array $byCodeCache = null;

    public function __construct(
        private readonly CurrencyRepository $currencies,
        private readonly RequestStack $requestStack,
        /** Used only until the database holds a default — see default(). */
        private readonly string $fallbackCode = 'CAD',
    ) {
    }

    /** @return Currency[] */
    public function enabled(): array
    {
        return $this->enabledCache ??= $this->currencies->findEnabled();
    }

    /**
     * The currency prices are authored in.
     *
     * Falls back to a code rather than failing when the table is empty — a
     * fresh database or a half-run migration must not take the shop down.
     */
    public function defaultCurrency(): ?Currency
    {
        return $this->defaultCache ??= $this->currencies->findDefault();
    }

    public function default(): string
    {
        return $this->defaultCurrency()?->getCode() ?? strtoupper($this->fallbackCode);
    }

    public function find(string $code): ?Currency
    {
        foreach ($this->enabled() as $currency) {
            if ($currency->getCode() === strtoupper($code)) {
                return $currency;
            }
        }

        return null;
    }

    public function isSupported(mixed $code): bool
    {
        return is_string($code) && $this->find($code) !== null;
    }

    /** Normalises a candidate code, falling back to the default. */
    public function normalize(mixed $code): string
    {
        return $this->isSupported($code) ? strtoupper((string) $code) : $this->default();
    }

    /** Lowercase form, which is what Stripe and PayPal expect in payloads. */
    public function forProvider(string $code): string
    {
        return strtolower($this->normalize($code));
    }

    /**
     * The currency this visitor is browsing in.
     *
     * Today: their explicit choice, else the shop default. Tomorrow: their
     * country decides, and this is the only method that changes.
     */
    public function resolveForVisitor(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request !== null && $request->hasSession(true)) {
            $chosen = $request->getSession()->get(self::SESSION_KEY);

            if ($this->isSupported($chosen)) {
                return strtoupper((string) $chosen);
            }
        }

        return $this->default();
    }

    /** Records an explicit choice. Returns the code actually applied. */
    public function rememberChoice(string $code): string
    {
        $code = $this->normalize($code);

        $request = $this->requestStack->getCurrentRequest();

        if ($request !== null && $request->hasSession(true)) {
            $request->getSession()->set(self::SESSION_KEY, $code);
        }

        return $code;
    }

    // ── Pricing ─────────────────────────────────────────────────────────────

    /**
     * What one article costs in a currency.
     *
     * An explicit ArticlePrice wins; otherwise the default price is converted
     * at the currency's rate. Conversion keeps a newly enabled currency usable
     * before every article has been hand-priced — it is a starting point, not
     * a pricing strategy.
     */
    public function priceFor(Article $article, string $code): float
    {
        $code = $this->normalize($code);

        $explicit = $article->getPriceFor($code);

        if ($explicit !== null) {
            return (float) $explicit;
        }

        return $this->convertFromDefault((float) $article->getPrice(), $code);
    }

    /**
     * Same, but after any active promotion — percentage promotions apply
     * cleanly in any currency, while amount-off and fixed-price promotions are
     * authored in the default currency and so are converted alongside it.
     */
    public function effectivePriceFor(Article $article, string $code): float
    {
        $code = $this->normalize($code);

        $explicit = $article->getPriceFor($code);

        if ($explicit === null) {
            return $this->convertFromDefault($article->getEffectivePrice(), $code);
        }

        // With a hand-set price, apply the promotion's ratio rather than its
        // raw amount, so a "$10 off" deal does not subtract 10 of the wrong
        // currency from a hand-set figure.
        $base = (float) $article->getPrice();
        $effective = $article->getEffectivePrice();
        $ratio = $base > 0 ? $effective / $base : 1.0;

        return round(((float) $explicit) * $ratio, 2);
    }

    public function convertFromDefault(float $amount, string $code): float
    {
        $currency = $this->find($code);

        if ($currency === null || $currency->isDefault()) {
            return round($amount, 2);
        }

        return round($amount * (float) $currency->getExchangeRate(), 2);
    }

    // ── Provider amounts ────────────────────────────────────────────────────

    public function toMinorUnits(string|float $amount, string $code): int
    {
        $value = (float) $amount;

        return $this->hasMinorUnit($code) ? (int) round($value * 100) : (int) round($value);
    }

    public function fromMinorUnits(int $minor, string $code): float
    {
        return $this->hasMinorUnit($code) ? $minor / 100 : (float) $minor;
    }

    public function hasMinorUnit(string $code): bool
    {
        return !in_array(strtoupper($code), self::ZERO_DECIMAL, true);
    }

    // ── Display ─────────────────────────────────────────────────────────────

    /** "CA$ 377.99" / "377,99 €", per the currency's own symbol settings. */
    public function format(float|string $amount, ?string $code = null): string
    {
        $code = $this->normalize($code ?? $this->resolveForVisitor());
        $currency = $this->find($code);
        $decimals = $this->hasMinorUnit($code) ? 2 : 0;

        $number = number_format((float) $amount, $decimals, ',', ' ');

        if ($currency === null) {
            return $number . ' ' . $code;
        }

        return $currency->getSymbolPosition() === 'after'
            ? $number . ' ' . $currency->getSymbol()
            : $currency->getSymbol() . ' ' . $number;
    }

    /**
     * Formats a historical amount in exactly the currency it was recorded in.
     *
     * Unlike format(), the code is never normalised to the default and the
     * currency is looked up across ALL currencies, not only the enabled ones.
     * A past order — in an email, in order history, in the back office — must
     * keep showing what the customer was actually charged, even after that
     * currency has since been disabled or deleted from the shop. If the row is
     * gone entirely, the amount is shown with its bare code (e.g. "272,15 EUR").
     */
    public function formatSnapshot(float|string $amount, ?string $code): string
    {
        $code = strtoupper((string) ($code ?: $this->default()));
        $currency = $this->currencyByCode($code);
        $decimals = $this->hasMinorUnit($code) ? 2 : 0;

        $number = number_format((float) $amount, $decimals, ',', ' ');

        if ($currency === null) {
            return $number . ' ' . $code;
        }

        return $currency->getSymbolPosition() === 'after'
            ? $number . ' ' . $currency->getSymbol()
            : $currency->getSymbol() . ' ' . $number;
    }

    /** All currencies, enabled or not, indexed by code — for historical lookups. */
    private function currencyByCode(string $code): ?Currency
    {
        if ($this->byCodeCache === null) {
            $this->byCodeCache = [];
            foreach ($this->currencies->findAllOrdered() as $currency) {
                $this->byCodeCache[$currency->getCode()] = $currency;
            }
        }

        return $this->byCodeCache[strtoupper($code)] ?? null;
    }
}
