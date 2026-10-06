<?php

namespace App\Service;

use App\Shipping\Adapter\CarrierAddressAdapter;
use App\Shipping\Adapter\SenderAddressAdapter;
use App\Shipping\Customs\CustomsDeclaration;
use App\Shipping\ShippingAddress;
use App\Shipping\Snapshot\ShippingRateSnapshotStore;
use Psr\Log\LoggerInterface;

// shippo/shippo-php uses global classes (no namespace): Shippo, Shippo_Address, Shippo_Parcel, etc.

class ShippingService
{
    public function __construct(
        string $apiKey,
        private readonly SenderAddressAdapter $sender,
        private readonly CarrierAddressAdapter $carrier,
        private readonly LoggerInterface $shippingLogger,
        private readonly ShippingRateSnapshotStore $snapshots,
    ) {
        \Shippo::setApiKey($apiKey);
    }

    /**
     * Returns the available shipping rates for a destination, with a degraded
     * mode: when the live carrier returns nothing, the latest known-good snapshot
     * for the same route/weight band is served instead of an empty list (see
     * ARCHITECTURE.md §5 "Shipping-rate snapshot fallback"). Snapshot rates are
     * flagged `from_snapshot => true` and carry `captured_at` so the caller (and
     * the customer) can tell they are a fallback.
     */
    public function getRates(array $toAddress, array $parcel, ?CustomsDeclaration $customs = null): array
    {
        $country = strtoupper(trim((string) ($toAddress['country'] ?? ''))) ?: $this->sender->address()->country;
        $region  = $toAddress['state'] ?? null;
        $weight  = (float) ($parcel['weight'] ?? 1);

        // The degraded mode covers both ways live rating can fail the customer:
        // the carrier returns no rates, or the call itself errors (Shippo down,
        // timeout, auth). Either way we try the snapshot before giving up.
        $liveError = null;
        try {
            $rates = $this->rateLive($toAddress, $parcel, $customs);
            if ($rates !== []) {
                // Opportunistic capture: a successful live rating for a real
                // customer address *is* the known-good snapshot for this route and
                // band. Storing it here means the fallback is warmed from real
                // traffic, with no probe list and no invented addresses. Never let
                // a capture failure (e.g. DB hiccup) break the customer's rating.
                try {
                    $this->snapshots->remember($country, $region, $weight, $rates);
                } catch (\Throwable $e) {
                    $this->shippingLogger->error('shipping.snapshot.capture_failed', [
                        'provider' => 'shippo',
                        'error'    => $e->getMessage(),
                    ]);
                }

                return $rates;
            }
        } catch (\Throwable $e) {
            $liveError = $e;
        }

        // Fall back to the most recent real rates captured for this route and
        // weight band (rateLive already logged the empty case).
        $snapshot = $this->snapshots->recall($country, $region, $weight);
        if ($snapshot === null) {
            // Nothing to serve. When live actually errored, re-throw so the caller
            // reports a real failure (the checkout turns this into a 502) rather
            // than masking an outage as "no options". An empty-but-successful live
            // result with no snapshot is simply no rates.
            if ($liveError !== null) {
                throw $liveError;
            }

            $this->shippingLogger->warning('shipping.rates.no_snapshot', [
                'provider'    => 'shippo',
                'destination' => trim(sprintf('%s/%s', $country, $region ?? '')),
                'weight'      => $weight,
            ]);

            return [];
        }

        $capturedAt = $snapshot->getCapturedAt()?->format(\DateTimeInterface::ATOM);
        $this->shippingLogger->warning('shipping.rates.snapshot_fallback', [
            'provider'    => 'shippo',
            'route_key'   => $snapshot->getRouteKey(),
            'captured_at' => $capturedAt,
            'rate_count'  => count($snapshot->getRates()),
            'reason'      => $liveError !== null ? 'live_error' : 'live_empty',
            'live_error'  => $liveError?->getMessage(),
        ]);

        return array_map(
            static fn (array $rate): array => $rate + ['from_snapshot' => true, 'captured_at' => $capturedAt],
            $snapshot->getRates(),
        );
    }

    /**
     * Rates a destination against the live carrier (Shippo) only — no fallback.
     * Returns an empty array when the carrier has no rates for the route; the
     * reason is logged. Used directly by the snapshot refresh command, which must
     * see the real live result to retry on empty and never store a fallback.
     *
     * The origin comes from the market (SenderAddressAdapter → home country +
     * admin settings), not a constant; both addresses pass through the neutral
     * ShippingAddress and the carrier adapter, so swapping Shippo touches the
     * adapter, not this flow. When the parcel crosses a border, Shippo's intl
     * rating requires a phone on both ends, enforced here before the API call.
     */
    public function rateLive(array $toAddress, array $parcel, ?CustomsDeclaration $customs = null): array
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

        $international = $to->isInternationalFrom($from);

        // Shippo's international rating guide makes a phone mandatory on both
        // the sender and the recipient; assert it before the call rather than
        // letting the API fail opaquely. Domestic shipments skip this.
        if ($international) {
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

        $shipmentPayload = [
            'address_from' => $fromAddress,
            'address_to'   => $addressTo,
            'parcels'      => [$parcelObj],
            'async'        => false,
        ];

        // A cross-border parcel must declare its contents to clear customs. The
        // declaration is built upstream (CartCustomsAdapter) and only attached
        // when the shipment actually leaves the origin country — a domestic
        // parcel carries none.
        if ($international && $customs !== null && !$customs->isEmpty()) {
            $shipmentPayload['customs_declaration'] = $this->createCustomsDeclaration($customs, $from);
        }

        $shipment = \Shippo_Shipment::create($shipmentPayload);

        if (($shipment['status'] ?? null) !== 'SUCCESS') {
            // The shipment itself failed to rate. Shippo says why in its messages;
            // log them so an empty result is never a mystery.
            $this->shippingLogger->error('shipping.shipment.not_success', [
                'provider' => 'shippo',
                'status'   => $shipment['status'] ?? null,
                'messages' => $this->extractMessages($shipment),
            ]);

            return [];
        }

        // Normalise the rates for display.
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

        // Cheapest first.
        usort($rates, fn($a, $b) => $a['price'] <=> $b['price']);

        // Only the live Shippo rates are ever returned — there is no simulated
        // fallback. When the list is empty the shipment still succeeded, so the
        // reason lives in Shippo's own messages (no enabled carrier account for
        // the route, a carrier key/endpoint mismatch, an unserviceable address…).
        // Surface it rather than mask it with mock rates.
        if (empty($rates)) {
            $this->shippingLogger->warning('shipping.rates.empty', [
                'provider'    => 'shippo',
                'destination' => trim(sprintf('%s/%s %s', $to->country, $to->state ?? '', $to->postalCode ?? '')),
                'messages'    => $this->extractMessages($shipment),
            ]);
        }

        return $rates;
    }

    /**
     * Flattens Shippo's shipment-level messages to "[source/code] text" lines —
     * the explanation for a failed or empty rating.
     *
     * @return array<int, string>
     */
    private function extractMessages(mixed $shipment): array
    {
        $out = [];
        foreach ($shipment['messages'] ?? [] as $m) {
            $code = isset($m['code']) && $m['code'] !== '' ? '/' . $m['code'] : '';
            $out[] = trim(sprintf('[%s%s] %s', $m['source'] ?? '?', $code, $m['text'] ?? ''));
        }

        return $out;
    }

    /**
     * Maps our neutral declaration to Shippo's customs objects and returns the
     * created declaration's id for the shipment. This is the carrier mapping for
     * customs — the Shippo-specific field names (`net_weight`, `value_amount`,
     * `certify_signer`) stay contained here, as they do for addresses.
     *
     * @return string the Shippo customs declaration object id
     */
    private function createCustomsDeclaration(CustomsDeclaration $declaration, ShippingAddress $from): string
    {
        $itemIds = [];
        foreach ($declaration->items as $item) {
            $created = \Shippo_CustomsItem::create([
                'description'   => $item->description,
                'quantity'      => $item->quantity,
                'net_weight'    => (string) $item->netWeightKg,
                'mass_unit'     => 'kg',
                'value_amount'  => number_format($item->valueAmount, 2, '.', ''),
                'value_currency' => $item->valueCurrency,
                'origin_country' => $item->originCountry,
                'sku'           => $item->sku ?? '',
                'tariff_number' => $item->hsCode ?? '',
            ]);
            $itemIds[] = $created['object_id'];
        }

        $created = \Shippo_CustomsDeclaration::create([
            'contents_type'       => $declaration->contentsType,
            'non_delivery_option' => $declaration->nonDeliveryOption,
            'certify'             => true,
            'certify_signer'      => $from->name,
            'items'               => $itemIds,
        ]);

        return $created['object_id'];
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