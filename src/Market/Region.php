<?php

namespace App\Market;

/**
 * A sub-national region (province, state, …) a market ships to and taxes.
 *
 * `code` is the stable key shared with tax rates and the order's shipping
 * region; `name` is an English default, translated in the UI via
 * "region.<country>.<code>" with this name as the fallback.
 */
final readonly class Region
{
    public function __construct(
        public string $code,
        public string $name,
    ) {
    }
}
