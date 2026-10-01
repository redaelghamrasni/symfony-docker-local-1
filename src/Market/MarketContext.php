<?php

namespace App\Market;

use App\Service\SettingService;
use Symfony\Component\Intl\Countries;

/**
 * The anchor of the market layer: answers "which country is this shop based in?"
 *
 * Today it only resolves the home country from the admin setting and lends a
 * localised country list to the UI. In the portability milestone it becomes the
 * input to MarketRegistry, which will map the home country to the active
 * MarketProfile (tax engine + regions + origin + currency). Keeping the lookup
 * here means there is a single place the hardcoded 'CA' default used to live.
 */
class MarketContext
{
    public const HOME_COUNTRY_KEY = 'home_country';
    private const FALLBACK_COUNTRY = 'CA';

    public function __construct(private readonly SettingService $settings)
    {
    }

    /** ISO 3166-1 alpha-2, uppercase. Falls back to CA for a fresh/empty shop. */
    public function homeCountry(): string
    {
        $value = strtoupper(trim((string) $this->settings->get(self::HOME_COUNTRY_KEY, self::FALLBACK_COUNTRY)));

        return Countries::exists($value) ? $value : self::FALLBACK_COUNTRY;
    }

    public function isValidCountry(string $code): bool
    {
        return Countries::exists(strtoupper(trim($code)));
    }

    /**
     * Localised ISO-code => name map for country selectors.
     *
     * Falls back to English names where the intl extension is absent (the
     * polyfill only collates 'en'); production ships with ext-intl.
     *
     * @return array<string, string>
     */
    public function countryNames(string $locale): array
    {
        try {
            return Countries::getNames($locale);
        } catch (\Throwable) {
            return Countries::getNames('en');
        }
    }
}
