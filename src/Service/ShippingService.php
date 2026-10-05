<?php

namespace App\Service;

use App\Shipping\Adapter\CarrierAddressAdapter;
use App\Shipping\Adapter\SenderAddressAdapter;
use App\Shipping\ShippingAddress;

// shippo/shippo-php uses global classes (no namespace): Shippo, Shippo_Address, Shippo_Parcel, etc.

class ShippingService
{
    public function __construct(
        string $apiKey,
        private readonly string $environment,
        private readonly SenderAddressAdapter $sender,
        private readonly CarrierAddressAdapter $carrier,
        private readonly CurrencyService $currencies,
    ) {
        \Shippo::setApiKey($apiKey);
    }

    /**
     * Returns the available shipping rates for a destination.
     *
     * The origin comes from the market (SenderAddressAdapter → home country +
     * admin settings), not a constant; both addresses pass through the neutral
     * ShippingAddress and the carrier adapter, so swapping Shippo touches the
     * adapter, not this flow. When the parcel crosses a border, Shippo's intl
     * rating requires a phone on both ends, enforced here before the API call.
     */
    public function getRates(array $toAddress, array $parcel): array
    {
        $from = $this->sender->address();
        $to   = new ShippingAddress(
            name:       (string) ($toAddress['name'] ?: 'Customer'),
            street1:    (string) $toAddress['street1'],
            city:       (string) $toAddress['city'],
            country:    strtoupper(trim((string) ($toAddress['country'] ?? $from->country))),
            state:      $toAddress['state'] ?? null,
            postalCode: $toAddress['zip'] ?? null,
            phone:      $toAddress['phone'] ?? null,
            email:      $toAddress['email'] ?? null,
        );

        // Shippo's international rating guide makes a phone mandatory on both
        // the sender and the recipient; assert it before the call rather than
        // letting the API fail opaquely. Domestic shipments skip this.
        if ($to->isInternationalFrom($from)) {
            $from->assertReadyForInternational('sender');
            $to->assertReadyForInternational('recipient');
        }

        $fromAddress = \Shippo_Address::create($this->carrier->toPayload($from));
        $addressTo   = \Shippo_Address::create($this->carrier->toPayload($to));

        $parcelObj = \Shippo_Parcel::create([
            'length'        => $parcel['length'] ?? '10',
            'width'         => $parcel['width']  ?? '10',
            'height'        => $parcel['height'] ?? '10',
            'distance_unit' => 'cm',
            'weight'        => $parcel['weight'] ?? '1',
            'mass_unit'     => 'kg',
        ]);

        $shipment = \Shippo_Shipment::create([
            'address_from' => $fromAddress,
            'address_to'   => $addressTo,
            'parcels'      => [$parcelObj],
            'async'        => false,
        ]);

        if ($shipment['status'] !== 'SUCCESS') {
            return [];
        }

        // Normalise les tarifs pour les afficher
        $rates = [];
        foreach ($shipment['rates'] as $rate) {
            $rates[] = [
                'object_id'      => $rate['object_id'],
                'carrier'        => $rate['provider'],
                'service'        => $rate['servicelevel']['name'],
                'price'          => $rate['amount'],
                'currency'       => $rate['currency'],
                'days'           => $rate['estimated_days'] ?? null,
                'duration_terms' => $rate['duration_terms'] ?? null,
            ];
        }

        // Trie par prix croissant
        usort($rates, fn($a, $b) => $a['price'] <=> $b['price']);

        // Aucun carrier Shippo n'est configuré pour ce compte (courant hors prod) :
        // on retombe sur des tarifs simulés pour ne pas bloquer le checkout en dev/test.
        if (empty($rates) && $this->environment !== 'prod') {
            return $this->mockRates();
        }

        return $rates;
    }

    private function mockRates(): array
    {
        // The simulated prices are shown in the shop's default currency, not a
        // hardcoded CAD, so a non-Canadian dev shop sees coherent amounts.
        $currency = $this->currencies->default();

        return [
            [
                'object_id'      => 'mock_standard',
                'carrier'        => 'Standard (simulé)',
                'service'        => 'Livraison standard',
                'price'          => '9.99',
                'currency'       => $currency,
                'days'           => 5,
                'duration_terms' => null,
            ],
            [
                'object_id'      => 'mock_express',
                'carrier'        => 'Express (simulé)',
                'service'        => 'Livraison express',
                'price'          => '19.99',
                'currency'       => $currency,
                'days'           => 2,
                'duration_terms' => null,
            ],
        ];
    }

    /**
     * Génère une étiquette d'expédition
     */
    public function createLabel(string $rateObjectId): ?array
    {
        $transaction = \Shippo_Transaction::create([
            'rate'           => $rateObjectId,
            'label_file_type' => 'PDF',
            'async'          => false,
        ]);

        if ($transaction['status'] !== 'SUCCESS') {
            return null;
        }

        return [
            'label_url'      => $transaction['label_url'],
            'tracking_number' => $transaction['tracking_number'],
            'tracking_url'   => $transaction['tracking_url_provider'],
        ];
    }
}