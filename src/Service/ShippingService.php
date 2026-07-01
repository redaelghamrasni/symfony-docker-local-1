<?php

namespace App\Service;

// shippo/shippo-php uses global classes (no namespace): Shippo, Shippo_Address, Shippo_Parcel, etc.

class ShippingService
{
    public function __construct(string $apiKey)
    {
        \Shippo::setApiKey($apiKey);
    }

    /**
     * Retourne les tarifs de livraison disponibles
     */
    public function getRates(array $toAddress, array $parcel): array
    {
        $fromAddress = \Shippo_Address::create([
            'name'    => 'MonApp Store',
            'street1' => '123 rue Principale',
            'city'    => 'Montréal',
            'state'   => 'QC',
            'zip'     => 'H1A1A1',
            'country' => 'CA',
            'phone'   => '+15140000000',
            'email'   => 'no-reply@monapp.local',
        ]);

        $addressTo = \Shippo_Address::create([
            'name'    => $toAddress['name'],
            'street1' => $toAddress['street1'],
            'city'    => $toAddress['city'],
            'state'   => $toAddress['state'] ?? 'QC',
            'zip'     => $toAddress['zip'],
            'country' => $toAddress['country'] ?? 'CA',
            'phone'   => $toAddress['phone'] ?? '',
            'email'   => $toAddress['email'] ?? '',
        ]);

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

        return $rates;
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