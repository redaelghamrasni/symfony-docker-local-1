<?php

namespace App\Command;

use App\Repository\OrderRepository;
use App\Repository\ShippingDestinationSeedRepository;
use App\Service\SettingService;
use App\Service\ShippingService;
use App\Shipping\Snapshot\ShippingRateSnapshotStore;
use App\Shipping\Snapshot\ShippingRouteKey;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Refreshes the known-good shipping-rate snapshots served as the checkout
 * fallback when Shippo returns no live rates (ROADMAP.md immediate priority #5).
 *
 * The destinations it rates are **real addresses, held in the database** — never
 * hardcoded in app config. Two sources, merged and deduped by route:
 *   - the seeded baseline (`shipping_destination_seed`, inserted by migration
 *     20261006130000, editable in the DB) so the fallback has real rates from
 *     day one, before any orders exist;
 *   - the distinct destinations the shop has actually shipped to, from order
 *     history (`OrderRepository::findDistinctShippingDestinations`).
 * Each is re-rated across every weight band (`ShippingRouteKey::sampleWeights()`,
 * derived from the band definition) and each non-empty result stored. Between
 * refreshes, the opportunistic capture in ShippingService keeps real customer
 * routes warm on every successful live rating.
 *
 * Rate limiting: one attempt per route per run, paced by `--throttle` seconds
 * between carrier calls. An empty result is NOT retried in-run — it is left for
 * the next scheduled run, so the schedule (hourly) is the retry cadence rather
 * than a tight loop that would hammer the API. Only a 429 is retried in-run,
 * after `--rate-limit-backoff` seconds. The store **never overwrites a good
 * snapshot with an empty one**, so repeated runs converge safely.
 *
 * Runs on server startup (docker/entrypoint.sh) and on an hourly cron. Safe to
 * run by hand; `--dry-run` rates without storing.
 */
#[AsCommand(
    name: 'app:shipping:refresh-snapshots',
    description: 'Capture live shipping rates as the checkout fallback snapshots',
)]
class RefreshShippingSnapshotsCommand extends Command
{
    /** Admin-tunable default pacing between carrier calls, in seconds. */
    public const THROTTLE_SETTING = 'shipping.snapshot.throttle';

    public function __construct(
        private readonly ShippingService $shippingService,
        private readonly ShippingRateSnapshotStore $snapshots,
        private readonly ShippingDestinationSeedRepository $seeds,
        private readonly OrderRepository $orders,
        private readonly SettingService $settings,
        private readonly LoggerInterface $shippingLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'since',
                null,
                InputOption::VALUE_REQUIRED,
                'How far back to look for shipping destinations in order history (e.g. 1 year, 180 days)',
                '1 year',
            )
            ->addOption(
                'throttle',
                null,
                InputOption::VALUE_REQUIRED,
                'Seconds to pause between carrier calls (fractional allowed). Overrides the admin setting "' . self::THROTTLE_SETTING . '"; when omitted, that setting is used (default 1).',
            )
            ->addOption(
                'rate-limit-backoff',
                null,
                InputOption::VALUE_REQUIRED,
                'Seconds to wait and retry once when the carrier returns 429 Too Many Requests',
                '60',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Rate every destination but store nothing',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Call spacing is admin-tunable (setting THROTTLE_SETTING); an explicit
        // --throttle on the CLI overrides it for a one-off run.
        $throttleOption = $input->getOption('throttle');
        $throttle = $throttleOption !== null
            ? (float) $throttleOption
            : $this->settings->getFloat(self::THROTTLE_SETTING, 1.0);
        $throttle = max(0.0, $throttle);

        $backoff = max(0, (int) $input->getOption('rate-limit-backoff'));
        $dryRun  = (bool) $input->getOption('dry-run');

        try {
            $since = new \DateTimeImmutable('-' . ltrim((string) $input->getOption('since'), '-'));
        } catch (\Exception $e) {
            $io->error(sprintf('Invalid --since value: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        // Two real-address sources, merged and deduped by route: the seeded
        // baseline (so the fallback has rates from day one) and the destinations
        // the shop has actually shipped to (so live customer routes stay covered).
        $destinations = $this->mergeDestinations(
            $this->seeds->findActiveDestinations(),
            $this->orders->findDistinctShippingDestinations($since),
        );
        $weights = ShippingRouteKey::sampleWeights();

        if ($destinations === []) {
            $io->warning('No seeded or order-history destinations to refresh. '
                . 'The baseline is seeded by migration 20261006130000 — ensure migrations have run.');

            return Command::SUCCESS;
        }

        $io->title(sprintf(
            'Refreshing shipping snapshots (%d real destinations × %d weight bands, %ss between calls)%s',
            count($destinations),
            count($weights),
            rtrim(rtrim(number_format($throttle, 3, '.', ''), '0'), '.'),
            $dryRun ? ' [dry-run]' : '',
        ));

        $stored = 0;
        $empty  = 0;
        $first  = true;

        foreach ($destinations as $destination) {
            foreach ($weights as $weight) {
                // Pace the calls to stay under the carrier's rate limit. One
                // attempt per route per run: an empty result is left for the next
                // scheduled run (hourly) rather than retried in a tight loop — the
                // store never overwrites a good snapshot with an empty one, so
                // repeated runs converge safely. Only a 429 is retried in-run,
                // after a backoff, so transient throttling does not skip a route.
                if (!$first && $throttle > 0) {
                    usleep((int) round($throttle * 1_000_000));
                }
                $first = false;

                $routeKey = ShippingRouteKey::for($destination['country'], $destination['region'], $weight);

                $rates = $this->rateOnce($destination, $weight, $backoff);

                if ($rates === []) {
                    ++$empty;
                    $io->writeln(sprintf('  <comment>%s</comment> — no rates (snapshot kept, retried next run)', $routeKey));
                    $this->shippingLogger->warning('shipping.snapshot.refresh_empty', [
                        'route_key' => $routeKey,
                    ]);
                    continue;
                }

                if (!$dryRun) {
                    $this->snapshots->remember($destination['country'], $destination['region'], $weight, $rates);
                }
                ++$stored;
                $io->writeln(sprintf('  <info>%s</info> — %d rates', $routeKey, count($rates)));
            }
        }

        $io->success(sprintf(
            '%s %d route(s); %d still without rates.',
            $dryRun ? 'Rated' : 'Stored',
            $stored,
            $empty,
        ));

        $this->shippingLogger->info('shipping.snapshot.refresh_done', [
            'stored'  => $stored,
            'empty'   => $empty,
            'dry_run' => $dryRun,
        ]);

        return Command::SUCCESS;
    }

    /**
     * Merges the seeded baseline with the order-history destinations, keeping one
     * row per route (country|region). The seed comes first so the day-one baseline
     * is always covered; an order-history row for the same route is a duplicate and
     * dropped (both rate the same snapshot key anyway).
     *
     * @param list<array{country: string, region: ?string, city: string, postalCode: string, phone: ?string}> $seeded
     * @param list<array{country: string, region: ?string, city: string, postalCode: string, phone: ?string}> $fromOrders
     *
     * @return list<array{country: string, region: ?string, city: string, postalCode: string, phone: ?string}>
     */
    private function mergeDestinations(array $seeded, array $fromOrders): array
    {
        $byRoute = [];
        foreach ([...$seeded, ...$fromOrders] as $destination) {
            $key = strtoupper($destination['country']) . '|' . ($destination['region'] ?? '*');
            $byRoute[$key] ??= $destination;
        }

        return array_values($byRoute);
    }

    /**
     * Rates one real destination at one weight — a single attempt. An empty result
     * or a non-rate-limit error returns [] and is left for the next scheduled run.
     * The one exception is a 429 (Too Many Requests): we back off once and retry,
     * so transient throttling does not drop a route from this run.
     *
     * @param array{country: string, region: ?string, city: string, postalCode: string, phone: ?string} $destination
     *
     * @return array<int, array<string, mixed>>
     */
    private function rateOnce(array $destination, float $weight, int $backoff): array
    {
        $toAddress = [
            'name'    => 'Rate probe',
            'street1' => '1 Main St',
            'city'    => $destination['city'],
            'zip'     => $destination['postalCode'],
            'state'   => $destination['region'],
            'country' => $destination['country'],
            'phone'   => $destination['phone'] ?? null,
        ];
        $parcel = ['weight' => $weight];

        for ($attempt = 0; $attempt <= 1; ++$attempt) {
            try {
                // rateLive, not getRates: we must store only real live rates, never
                // a fallback snapshot recalled for this very route.
                return $this->shippingService->rateLive($toAddress, $parcel);
            } catch (\Throwable $e) {
                $rateLimited = $this->isRateLimited($e);

                $this->shippingLogger->error('shipping.snapshot.probe_failed', [
                    'country'      => $destination['country'],
                    'region'       => $destination['region'],
                    'error_class'  => $e::class,
                    'error'        => $e->getMessage(),
                    'rate_limited' => $rateLimited,
                ]);

                // Back off and retry once only on a rate-limit response; any other
                // failure is structural and retried on the next scheduled run.
                if ($rateLimited && $attempt === 0 && $backoff > 0) {
                    sleep($backoff);
                    continue;
                }

                return [];
            }
        }

        return [];
    }

    /** A carrier 429 Too Many Requests, surfaced by the Shippo client. */
    private function isRateLimited(\Throwable $e): bool
    {
        // The Shippo client throws Shippo_RateLimitError for 429s, but its current
        // ApiRequestor maps 429 to the generic Shippo_ApiError instead — so trust
        // the HTTP status, whichever error class carries it.
        return $e instanceof \Shippo_Error && $e->getHttpStatus() === 429;
    }
}
