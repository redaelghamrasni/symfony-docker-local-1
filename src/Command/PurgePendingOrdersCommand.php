<?php

namespace App\Command;

use App\Entity\Order;
use App\Entity\Quote;
use App\Message\ReindexEntityMessage;
use App\Repository\OrderRepository;
use App\Repository\QuoteRepository;
use App\Service\OrderFinalizer;
use App\Service\PaymentOutcome;
use App\Service\QuoteConverter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\StripeClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Cleans up stale checkout state left behind by payments that never completed —
 * and doubles as a reconciliation safety net for the Stripe webhook.
 *
 * Two passes, both age-gated by --older-than and both reconciled against Stripe
 * before anything is abandoned:
 *   1. legacy/leftover orders still `pending` (an order is now created only at
 *      conversion, but a conversion that never finalized can leave one);
 *   2. the quotes themselves — the mutable pre-payment object that always exists
 *      before a payment is attempted, so this is the primary place a
 *      charged-but-abandoned checkout is recovered (converted → paid) or retired.
 *
 * It never deletes blindly on age. For each pending order past the threshold it
 * re-queries Stripe for the PaymentIntent first:
 *   - succeeded          → the webhook (and browser return) both missed it;
 *                          replay OrderFinalizer to recover the order (→ paid).
 *   - processing /
 *     requires_capture   → still in flight; leave it pending, try again later.
 *   - canceled /
 *     requires_payment_method / no PaymentIntent → genuinely abandoned.
 *
 * The threshold (default 24h) is far beyond any PaymentElement confirmation
 * window (seconds), so no legitimate in-progress payment is ever caught.
 *
 * Abandoned orders are marked `abandoned`, never deleted, unless --delete is
 * passed for a hard purge.
 */
#[AsCommand(
    name: 'app:checkout:purge-pending',
    description: 'Reconcile and clean up stale pending orders against Stripe',
)]
class PurgePendingOrdersCommand extends Command
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly QuoteRepository $quotes,
        private readonly EntityManagerInterface $em,
        private readonly StripeClient $stripeClient,
        private readonly OrderFinalizer $orderFinalizer,
        private readonly QuoteConverter $quoteConverter,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $paymentLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'older-than',
                null,
                InputOption::VALUE_REQUIRED,
                'Minimum age of a pending order to consider, as a relative interval (e.g. 24h, 48h, 2d)',
                '24h',
            )
            ->addOption(
                'delete',
                null,
                InputOption::VALUE_NONE,
                'Hard-delete abandoned orders instead of marking them "abandoned"',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Report what would happen without changing anything',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $olderThan = (string) $input->getOption('older-than');
        $hardDelete = (bool) $input->getOption('delete');
        $dryRun = (bool) $input->getOption('dry-run');

        $tz = new \DateTimeZone('America/Toronto');
        $cutoffTs = strtotime('-' . $olderThan, (new \DateTimeImmutable('now', $tz))->getTimestamp());
        if ($cutoffTs === false) {
            $io->error(sprintf('Invalid --older-than value: "%s". Use e.g. 24h, 48h, 2d.', $olderThan));

            return Command::INVALID;
        }
        $cutoff = (new \DateTimeImmutable('now', $tz))->setTimestamp($cutoffTs);

        $candidates = $this->orders->findPendingOlderThan($cutoff);

        $io->title('Purge pending orders');
        $io->writeln(sprintf(
            'Cutoff: pending and untouched since before <info>%s</info> (older than %s)%s',
            $cutoff->format('Y-m-d H:i T'),
            $olderThan,
            $dryRun ? ' — <comment>dry run</comment>' : '',
        ));
        $io->writeln(sprintf('Candidates: <info>%d</info>', count($candidates)));

        $recovered = $inFlight = $abandoned = $unreachable = 0;

        // Reindex/removal messages are dispatched only after the flush below, so
        // the handler never races the DB whatever transport it runs on.
        /** @var ReindexEntityMessage[] $pendingDispatches */
        $pendingDispatches = [];

        foreach ($candidates as $order) {
            // The order may have been finalized between the query and now.
            if ($order->getStatus() !== 'pending') {
                continue;
            }

            $piId = $order->getStripePaymentIntentId();

            // No PaymentIntent (PayPal flow that never captured, or a Stripe
            // order whose intent was never linked): nothing to reconcile.
            if (!$piId) {
                $this->abandon($order, $io, $hardDelete, $dryRun, 'no_payment_intent', $pendingDispatches);
                $abandoned++;
                continue;
            }

            try {
                $intent = $this->stripeClient->paymentIntents->retrieve($piId);
            } catch (\Throwable $e) {
                // Could not reach Stripe: never abandon on uncertainty. Leave it
                // pending for the next run.
                $unreachable++;
                $this->paymentLogger->warning('checkout.purge.stripe_unreachable', [
                    'order_id'       => $order->getId(),
                    'payment_intent' => $piId,
                    'error'          => $e->getMessage(),
                ]);
                $io->writeln(sprintf('  #%d — Stripe unreachable, left pending', $order->getId()));
                continue;
            }

            $status = $intent->status ?? null;

            if ($status === 'succeeded') {
                $io->writeln(sprintf('  #%d — PaymentIntent succeeded, recovering → paid', $order->getId()));
                $this->paymentLogger->warning('checkout.purge.recovered', [
                    'order_id'       => $order->getId(),
                    'payment_intent' => $piId,
                    'note'           => 'succeeded payment had no finalized order; webhook likely missed',
                ]);

                if (!$dryRun) {
                    $outcome = new PaymentOutcome(
                        provider: 'stripe',
                        providerConfirmed: true,
                        paidCurrency: $intent->currency !== null ? strtolower((string) $intent->currency) : null,
                        paidMinor: isset($intent->amount_received) ? (int) $intent->amount_received : null,
                        reference: $piId,
                        paymentMethod: ($intent->payment_method_types[0] ?? null) ?: 'card',
                    );
                    $this->orderFinalizer->finalizePaid($order, $outcome);
                }
                $recovered++;
                continue;
            }

            if (in_array($status, ['processing', 'requires_capture'], true)) {
                // Still in flight — do not purge, do not finalize.
                $inFlight++;
                $io->writeln(sprintf('  #%d — PaymentIntent %s, left pending', $order->getId(), $status));
                continue;
            }

            if (in_array($status, ['canceled', 'requires_payment_method'], true)) {
                $this->abandon($order, $io, $hardDelete, $dryRun, 'stripe_' . $status, $pendingDispatches);
                $abandoned++;
                continue;
            }

            // requires_action / requires_confirmation and the like: the customer
            // may still complete it. Leave pending.
            $inFlight++;
            $io->writeln(sprintf('  #%d — PaymentIntent %s, left pending', $order->getId(), $status ?? 'unknown'));
        }

        if (!$dryRun) {
            $this->em->flush();

            // DB is committed; now it is safe to tell the search index.
            foreach ($pendingDispatches as $message) {
                $this->messageBus->dispatch($message);
            }
        }

        $io->newLine();
        $io->success(sprintf(
            'Orders — Recovered: %d · In flight (kept): %d · Abandoned%s: %d · Stripe unreachable: %d',
            $recovered,
            $inFlight,
            $hardDelete ? ' (deleted)' : '',
            $abandoned,
            $unreachable,
        ));

        // Second pass: the quotes themselves. A quote always exists before a
        // payment is attempted (unlike the old pending order), so this is the
        // primary place a charged-but-abandoned checkout is recovered.
        $this->reconcileQuotes($io, $cutoff, $olderThan, $hardDelete, $dryRun);

        return Command::SUCCESS;
    }

    /**
     * Reconciles stale unconverted quotes against the provider, mirroring the
     * order pass: a Stripe quote whose PaymentIntent actually succeeded is
     * recovered (converted → paid), one that is still in flight is kept, and a
     * genuinely dead one is abandoned (soft by default, hard-deleted with
     * --delete). A quote is never abandoned on uncertainty (Stripe unreachable).
     */
    private function reconcileQuotes(
        SymfonyStyle $io,
        \DateTimeImmutable $cutoff,
        string $olderThan,
        bool $hardDelete,
        bool $dryRun,
    ): void {
        $candidates = $this->quotes->findStaleUnconvertedOlderThan($cutoff);

        $io->section('Quotes');
        $io->writeln(sprintf(
            'Cutoff: unconverted and untouched since before <info>%s</info> (older than %s)%s',
            $cutoff->format('Y-m-d H:i T'),
            $olderThan,
            $dryRun ? ' — <comment>dry run</comment>' : '',
        ));
        $io->writeln(sprintf('Candidates: <info>%d</info>', count($candidates)));

        $recovered = $inFlight = $abandoned = $unreachable = 0;

        foreach ($candidates as $quote) {
            // It may have been converted between the query and now.
            if ($quote->isConverted()) {
                continue;
            }

            $provider  = $quote->getPaymentProvider();
            $reference = $quote->getPaymentReference();

            // Only a Stripe quote carrying a PaymentIntent can be reconciled
            // against Stripe. Anything else (a draft that never reached payment,
            // or a PayPal quote that never captured) is treated as abandoned.
            if ($provider !== 'stripe' || !$reference) {
                $this->abandonQuote($quote, $io, $hardDelete, $dryRun, $provider === null ? 'no_payment' : 'unreconcilable_' . $provider);
                $abandoned++;
                continue;
            }

            try {
                $intent = $this->stripeClient->paymentIntents->retrieve($reference);
            } catch (\Throwable $e) {
                $unreachable++;
                $this->paymentLogger->warning('checkout.purge.quote.stripe_unreachable', [
                    'quote_id'       => $quote->getId(),
                    'payment_intent' => $reference,
                    'error'          => $e->getMessage(),
                ]);
                $io->writeln(sprintf('  quote #%d — Stripe unreachable, left open', $quote->getId()));
                continue;
            }

            $status = $intent->status ?? null;

            if ($status === 'succeeded') {
                $io->writeln(sprintf('  quote #%d — PaymentIntent succeeded, recovering → order', $quote->getId()));
                $this->paymentLogger->warning('checkout.purge.quote.recovered', [
                    'quote_id'       => $quote->getId(),
                    'payment_intent' => $reference,
                    'note'           => 'succeeded payment had no converted order; webhook likely missed',
                ]);

                if (!$dryRun) {
                    $order   = $this->quoteConverter->convert($quote);
                    $outcome = new PaymentOutcome(
                        provider: 'stripe',
                        providerConfirmed: true,
                        paidCurrency: $intent->currency !== null ? strtolower((string) $intent->currency) : null,
                        paidMinor: isset($intent->amount_received) ? (int) $intent->amount_received : null,
                        reference: $reference,
                        paymentMethod: ($intent->payment_method_types[0] ?? null) ?: 'card',
                    );
                    $this->orderFinalizer->finalizePaid($order, $outcome);
                }
                $recovered++;
                continue;
            }

            if (in_array($status, ['processing', 'requires_capture'], true)) {
                $inFlight++;
                $io->writeln(sprintf('  quote #%d — PaymentIntent %s, left open', $quote->getId(), $status));
                continue;
            }

            if (in_array($status, ['canceled', 'requires_payment_method'], true)) {
                $this->abandonQuote($quote, $io, $hardDelete, $dryRun, 'stripe_' . $status);
                $abandoned++;
                continue;
            }

            // requires_action / requires_confirmation and the like: the customer
            // may still complete it. Leave open.
            $inFlight++;
            $io->writeln(sprintf('  quote #%d — PaymentIntent %s, left open', $quote->getId(), $status ?? 'unknown'));
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf(
            'Quotes — Recovered: %d · In flight (kept): %d · Abandoned%s: %d · Stripe unreachable: %d',
            $recovered,
            $inFlight,
            $hardDelete ? ' (deleted)' : '',
            $abandoned,
            $unreachable,
        ));
    }

    private function abandonQuote(
        Quote $quote,
        SymfonyStyle $io,
        bool $hardDelete,
        bool $dryRun,
        string $reason,
    ): void {
        $id = $quote->getId();

        if ($hardDelete) {
            $io->writeln(sprintf('  quote #%d — abandoned (%s), deleting', $id, $reason));
            if (!$dryRun) {
                // Quotes are not search-indexed, so there is nothing to reindex.
                $this->em->remove($quote);
            }
        } else {
            $io->writeln(sprintf('  quote #%d — marking abandoned (%s)', $id, $reason));
            if (!$dryRun) {
                $quote->markAbandoned();
                $quote->setUpdatedAt(new \DateTimeImmutable('now', new \DateTimeZone('America/Toronto')));
            }
        }

        $this->paymentLogger->info('checkout.purge.quote.abandoned', [
            'quote_id' => $id,
            'reason'   => $reason,
            'mode'     => $hardDelete ? 'deleted' : 'marked_abandoned',
            'dry_run'  => $dryRun,
        ]);
    }

    /**
     * @param ReindexEntityMessage[] $pendingDispatches collected, dispatched after the flush
     */
    private function abandon(
        Order $order,
        SymfonyStyle $io,
        bool $hardDelete,
        bool $dryRun,
        string $reason,
        array &$pendingDispatches,
    ): void {
        $id = $order->getId();

        if ($hardDelete) {
            $io->writeln(sprintf('  #%d — abandoned (%s), deleting', $id, $reason));
            if (!$dryRun) {
                $this->em->remove($order);
                $pendingDispatches[] = new ReindexEntityMessage('order', $id, true);
            }
        } else {
            $io->writeln(sprintf('  #%d — marking abandoned (%s)', $id, $reason));
            if (!$dryRun) {
                $order->setStatus('abandoned');
                $order->setUpdatedAt(new \DateTimeImmutable('now', new \DateTimeZone('America/Toronto')));
                $pendingDispatches[] = new ReindexEntityMessage('order', $id, false);
            }
        }

        $this->paymentLogger->info('checkout.purge.abandoned', [
            'order_id' => $id,
            'reason'   => $reason,
            'mode'     => $hardDelete ? 'deleted' : 'marked_abandoned',
            'dry_run'  => $dryRun,
        ]);
    }
}
