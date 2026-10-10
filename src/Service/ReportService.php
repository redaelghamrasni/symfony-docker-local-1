<?php

namespace App\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Read-only analytics for the back-office Reports section and the dashboard
 * synthesis. All aggregation lives here (native SQL on the default MySQL
 * connection) so the Reports page and the home dashboard read the same numbers.
 *
 * Revenue counts an order once its payment has gone through — i.e. any paid
 * lifecycle status, never `pending`/`cancelled`/`abandoned`. Amounts are summed
 * per currency (never across currencies) so a mixed-currency shop stays correct;
 * callers that need a single figure use the dominant currency.
 */
class ReportService
{
    /** Order statuses that represent a completed sale (payment went through). */
    public const PAID_STATUSES = ['paid', 'in_progress', 'shipped', 'completed'];

    /** Quote statuses that are a live recovery opportunity (not yet converted). */
    private const RECOVERABLE_STATUSES = ['ready', 'abandoned'];

    public function __construct(private readonly Connection $conn) {}

    private static function fmt(\DateTimeInterface $d): string
    {
        return $d->format('Y-m-d H:i:s');
    }

    // ── 1. Conversion funnel ─────────────────────────────────────────────────

    /**
     * Funnel over quotes CREATED in the window: created → reached-ready →
     * converted, plus abandoned and still-open, with rates and the dollar value
     * sitting in abandoned carts.
     */
    public function conversionFunnel(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $row = $this->conn->executeQuery(
            'SELECT
                COUNT(*) AS created,
                COALESCE(SUM(ready_at IS NOT NULL), 0) AS reached_ready,
                COALESCE(SUM(status = :converted), 0) AS converted,
                COALESCE(SUM(status = :abandoned), 0) AS abandoned,
                COALESCE(SUM(CASE WHEN status = :abandoned THEN total ELSE 0 END), 0) AS abandoned_value
             FROM quote
             WHERE created_at >= :from AND created_at < :to',
            ['converted' => 'converted', 'abandoned' => 'abandoned', 'from' => self::fmt($from), 'to' => self::fmt($to)],
        )->fetchAssociative() ?: [];

        $created   = (int) ($row['created'] ?? 0);
        $converted = (int) ($row['converted'] ?? 0);
        $abandoned = (int) ($row['abandoned'] ?? 0);

        return [
            'created'          => $created,
            'reached_ready'    => (int) ($row['reached_ready'] ?? 0),
            'converted'        => $converted,
            'abandoned'        => $abandoned,
            'open'             => max(0, $created - $converted - $abandoned),
            'abandoned_value'  => (float) ($row['abandoned_value'] ?? 0),
            'conversion_rate'  => $created > 0 ? $converted / $created * 100 : null,
            'abandonment_rate' => $created > 0 ? $abandoned / $created * 100 : null,
        ];
    }

    /**
     * Per-day created vs converted quote counts, for the trend chart.
     * @return list<array{date:string, created:int, converted:int}>
     */
    public function funnelTrend(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT DATE(created_at) AS d,
                    COUNT(*) AS created,
                    COALESCE(SUM(status = :converted), 0) AS converted
             FROM quote
             WHERE created_at >= :from AND created_at < :to
             GROUP BY DATE(created_at) ORDER BY d',
            ['converted' => 'converted', 'from' => self::fmt($from), 'to' => self::fmt($to)],
        )->fetchAllAssociative();

        return array_map(static fn (array $r): array => [
            'date'      => (string) $r['d'],
            'created'   => (int) $r['created'],
            'converted' => (int) $r['converted'],
        ], $rows);
    }

    // ── 2. Abandoned carts ───────────────────────────────────────────────────

    /**
     * Recoverable carts (ready/abandoned, not converted) that carry a contact
     * email and are at least an hour old — the actionable list for reminders.
     * @return list<array<string,mixed>>
     */
    public function abandonedCarts(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 50): array
    {
        $rows = $this->conn->executeQuery(
            'SELECT q.id, q.status, q.customer_email, q.customer_first_name, q.customer_last_name,
                    q.total, q.currency, q.created_at, q.abandoned_at,
                    (SELECT COUNT(*) FROM quote_item qi WHERE qi.quote_id = q.id) AS item_count
             FROM quote q
             WHERE q.status IN (:statuses)
               AND q.customer_email IS NOT NULL AND q.customer_email <> ""
               AND q.created_at >= :from AND q.created_at < :to
               AND q.created_at < (NOW() - INTERVAL 1 HOUR)
             ORDER BY q.created_at DESC
             LIMIT ' . (int) $limit,
            ['statuses' => self::RECOVERABLE_STATUSES, 'from' => self::fmt($from), 'to' => self::fmt($to)],
            ['statuses' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        return $rows;
    }

    /** Count and total value of recoverable carts in the window. */
    public function abandonedSummary(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $row = $this->conn->executeQuery(
            'SELECT COUNT(*) AS carts, COALESCE(SUM(total), 0) AS value,
                    (SELECT currency FROM quote ORDER BY id DESC LIMIT 1) AS currency
             FROM quote q
             WHERE q.status IN (:statuses)
               AND q.customer_email IS NOT NULL AND q.customer_email <> ""
               AND q.created_at >= :from AND q.created_at < :to
               AND q.created_at < (NOW() - INTERVAL 1 HOUR)',
            ['statuses' => self::RECOVERABLE_STATUSES, 'from' => self::fmt($from), 'to' => self::fmt($to)],
            ['statuses' => ArrayParameterType::STRING],
        )->fetchAssociative() ?: [];

        return [
            'carts'    => (int) ($row['carts'] ?? 0),
            'value'    => (float) ($row['value'] ?? 0),
            'currency' => $row['currency'] ?? null,
        ];
    }

    // ── 3. Sales & revenue ───────────────────────────────────────────────────

    /** The currency with the most paid orders in the window (for single-figure KPIs). */
    public function dominantCurrency(\DateTimeInterface $from, \DateTimeInterface $to): ?string
    {
        $cur = $this->conn->executeQuery(
            'SELECT currency FROM `order`
             WHERE status IN (:paid) AND created_at >= :from AND created_at < :to
             GROUP BY currency ORDER BY COUNT(*) DESC LIMIT 1',
            ['paid' => self::PAID_STATUSES, 'from' => self::fmt($from), 'to' => self::fmt($to)],
            ['paid' => ArrayParameterType::STRING],
        )->fetchOne();

        return $cur !== false ? (string) $cur : null;
    }

    /**
     * Revenue, order count and AOV per currency, plus breakdowns by province and
     * payment method, plus a per-day revenue trend in the dominant currency.
     */
    public function salesSummary(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $params = ['paid' => self::PAID_STATUSES, 'from' => self::fmt($from), 'to' => self::fmt($to)];
        $types  = ['paid' => ArrayParameterType::STRING];

        $byCurrency = $this->conn->executeQuery(
            'SELECT currency, COUNT(*) AS orders, COALESCE(SUM(total),0) AS revenue, COALESCE(AVG(total),0) AS aov
             FROM `order` WHERE status IN (:paid) AND created_at >= :from AND created_at < :to
             GROUP BY currency ORDER BY revenue DESC',
            $params, $types,
        )->fetchAllAssociative();

        $byProvince = $this->conn->executeQuery(
            'SELECT COALESCE(NULLIF(shipping_province, ""), "—") AS province, shipping_country AS country,
                    COUNT(*) AS orders, COALESCE(SUM(total),0) AS revenue, currency
             FROM `order` WHERE status IN (:paid) AND created_at >= :from AND created_at < :to
             GROUP BY shipping_province, shipping_country, currency ORDER BY revenue DESC LIMIT 15',
            $params, $types,
        )->fetchAllAssociative();

        $byMethod = $this->conn->executeQuery(
            'SELECT COALESCE(NULLIF(payment_method, ""), "unknown") AS method,
                    COUNT(*) AS orders, COALESCE(SUM(total),0) AS revenue, currency
             FROM `order` WHERE status IN (:paid) AND created_at >= :from AND created_at < :to
             GROUP BY payment_method, currency ORDER BY revenue DESC',
            $params, $types,
        )->fetchAllAssociative();

        $dominant = $this->dominantCurrency($from, $to);
        $trend = [];
        if ($dominant !== null) {
            $trend = $this->conn->executeQuery(
                'SELECT DATE(created_at) AS d, COUNT(*) AS orders, COALESCE(SUM(total),0) AS revenue
                 FROM `order`
                 WHERE status IN (:paid) AND currency = :cur AND created_at >= :from AND created_at < :to
                 GROUP BY DATE(created_at) ORDER BY d',
                $params + ['cur' => $dominant], $types,
            )->fetchAllAssociative();
        }

        return [
            'by_currency'       => $byCurrency,
            'by_province'       => $byProvince,
            'by_method'         => $byMethod,
            'dominant_currency' => $dominant,
            'trend'             => array_map(static fn (array $r): array => [
                'date'    => (string) $r['d'],
                'orders'  => (int) $r['orders'],
                'revenue' => (float) $r['revenue'],
            ], $trend),
        ];
    }

    // ── 4. Product performance ───────────────────────────────────────────────

    /** Top products by units sold (paid orders) in the window. */
    public function topProducts(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 10): array
    {
        return $this->conn->executeQuery(
            'SELECT a.id, a.title, SUM(oi.quantity) AS units, COALESCE(SUM(oi.subtotal),0) AS revenue, o.currency
             FROM order_item oi
             JOIN `order` o ON o.id = oi.order_id
             JOIN article a ON a.id = oi.article_id
             WHERE o.status IN (:paid) AND o.created_at >= :from AND o.created_at < :to
             GROUP BY a.id, a.title, o.currency ORDER BY units DESC LIMIT ' . (int) $limit,
            ['paid' => self::PAID_STATUSES, 'from' => self::fmt($from), 'to' => self::fmt($to)],
            ['paid' => ArrayParameterType::STRING],
        )->fetchAllAssociative();
    }

    /** Products most often left in recoverable (ready/abandoned) carts. */
    public function topAbandonedProducts(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 10): array
    {
        return $this->conn->executeQuery(
            'SELECT a.id, a.title, SUM(qi.quantity) AS units, COUNT(DISTINCT q.id) AS carts
             FROM quote_item qi
             JOIN quote q ON q.id = qi.quote_id
             JOIN article a ON a.id = qi.article_id
             WHERE q.status IN (:statuses)
               AND q.created_at >= :from AND q.created_at < :to
             GROUP BY a.id, a.title ORDER BY units DESC LIMIT ' . (int) $limit,
            ['statuses' => self::RECOVERABLE_STATUSES, 'from' => self::fmt($from), 'to' => self::fmt($to)],
            ['statuses' => ArrayParameterType::STRING],
        )->fetchAllAssociative();
    }

    // ── Dashboard synthesis (compact, last 30 days) ──────────────────────────

    /**
     * A compact synthesis for the admin home page: lifetime totals plus the key
     * last-30-days KPIs, including the marketing ones (conversion, abandoned value).
     */
    public function dashboardSummary(): array
    {
        $tz   = new \DateTimeZone('America/Toronto');
        $to   = new \DateTimeImmutable('now', $tz);
        $from = $to->modify('-30 days');

        $funnel   = $this->conversionFunnel($from, $to);
        $sales    = $this->salesSummary($from, $to);
        $abandon  = $this->abandonedSummary($from, $to);

        // Revenue/AOV in the dominant currency (most paid orders) over 30 days.
        $dominant = $sales['dominant_currency'];
        $revenue = 0.0; $orders = 0; $aov = 0.0;
        foreach ($sales['by_currency'] as $r) {
            if ($dominant !== null && $r['currency'] === $dominant) {
                $revenue = (float) $r['revenue']; $orders = (int) $r['orders']; $aov = (float) $r['aov'];
            }
        }

        // Orders that need a human look: payment could not be verified.
        $flagged = (int) $this->conn->executeQuery(
            'SELECT COUNT(*) FROM `order` WHERE payment_verified = 0'
        )->fetchOne();

        return [
            'revenue_30d'      => $revenue,
            'orders_30d'       => $orders,
            'aov_30d'          => $aov,
            'currency'         => $dominant,
            'quotes_created_30d' => $funnel['created'],
            'conversion_rate_30d' => $funnel['conversion_rate'],
            'abandoned_carts'  => $abandon['carts'],
            'abandoned_value'  => $abandon['value'],
            'abandoned_currency' => $abandon['currency'],
            'flagged_orders'   => $flagged,
        ];
    }
}
