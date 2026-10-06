<?php

namespace App\Shipping\Adapter;

use App\Market\MarketContext;
use App\Service\SettingService;
use App\Shipping\ShippingAddress;

/**
 * Builds the sender (origin) address — where parcels ship *from*.
 *
 * The origin is read from admin settings (`shipping.origin.*`), so an operator
 * configures their own warehouse without a code change. The one value that need
 * not be entered is the country: it defaults to the shop's home country
 * (MarketContext), the same setting that drives tax and regions — a Canadian
 * shop ships from Canada unless told otherwise.
 *
 * A bundled fallback keeps a fresh shop (empty settings) rating and shipping on
 * day one, in dev and in tests, rather than throwing on an incomplete origin.
 * For international shipping the origin must be a real, complete address: it is
 * what customs and the carrier bill and, on a return, ship back to — so an
 * operator going cross-border is expected to replace the fallback with their
 * real address in the admin.
 */
final class SenderAddressAdapter
{
    public const SETTING_PREFIX = 'shipping.origin.';

    /**
     * Dev/day-one default used field by field when a setting is blank. It is a
     * complete, valid address so ShippingAddress never rejects it; an operator
     * overrides any field from the admin.
     */
    private const FALLBACK = [
        'name'        => 'Store',
        'street1'     => '123 rue Principale',
        'city'        => 'Montréal',
        'state'       => 'QC',
        'postal_code' => 'H1A1A1',
        'phone'       => '+15140000000',
        'email'       => 'no-reply@example.com',
        'company'     => null,
        'street2'     => null,
    ];

    public function __construct(
        private readonly SettingService $settings,
        private readonly MarketContext $market,
    ) {
    }

    public function address(): ShippingAddress
    {
        $country = strtoupper(trim((string) $this->setting('country', '')));
        if ($country === '') {
            $country = $this->market->homeCountry();
        }

        return new ShippingAddress(
            name:       $this->setting('name', self::FALLBACK['name']),
            street1:    $this->setting('street1', self::FALLBACK['street1']),
            city:       $this->setting('city', self::FALLBACK['city']),
            country:    $country,
            state:      $this->setting('state', self::FALLBACK['state']),
            postalCode: $this->setting('postal_code', self::FALLBACK['postal_code']),
            street2:    $this->setting('street2', self::FALLBACK['street2']),
            phone:      $this->setting('phone', self::FALLBACK['phone']),
            email:      $this->setting('email', self::FALLBACK['email']),
            company:    $this->setting('company', self::FALLBACK['company']),
        );
    }

    /** A blank setting falls back to the default rather than an empty string. */
    private function setting(string $key, ?string $default): ?string
    {
        $value = $this->settings->get(self::SETTING_PREFIX . $key);
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? $default : (string) $value;
    }
}
