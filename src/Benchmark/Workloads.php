<?php

declare(strict_types=1);

namespace Asyncopath\Benchmark;

/**
 * Workloads shared by the sync (PHP-FPM) and async (AMPHP) benchmark servers.
 *
 * Both servers run exactly the same SQL and the same CPU work, so the only
 * difference between them is the execution model.
 */
final class Workloads
{
    public const REGIONS = ['North America', 'Europe', 'Asia'];

    /**
     * Pure I/O wait: the database does no real work, the PHP worker just waits.
     * This is the case where async should win by the widest margin.
     */
    public const SLEEP_SQL = 'SELECT pg_sleep(?) AS slept';

    /**
     * Heavy analytical query filtered by region: joins, aggregation and window functions.
     */
    public const REPORT_SQL = <<<'SQL'
WITH monthly_category_stats AS (
    SELECT
        p.category,
        TO_CHAR(o.order_date, 'YYYY-MM') AS sales_month,
        COUNT(DISTINCT o.customer_id) AS unique_customers,
        SUM(oi.quantity * oi.unit_price) AS total_revenue
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN products p ON oi.product_id = p.id
    WHERE o.region = ?
      AND o.order_date >= NOW() - INTERVAL '1 year'
    GROUP BY p.category, TO_CHAR(o.order_date, 'YYYY-MM')
)
SELECT
    category,
    sales_month,
    unique_customers,
    total_revenue,
    AVG(total_revenue) OVER (
        PARTITION BY category
        ORDER BY sales_month
        ROWS BETWEEN 2 PRECEDING AND CURRENT ROW
    ) AS moving_avg_revenue,
    RANK() OVER (
        PARTITION BY sales_month
        ORDER BY total_revenue DESC
    ) AS category_rank_in_month
FROM monthly_category_stats
ORDER BY sales_month DESC, total_revenue DESC
SQL;

    /**
     * Light indexed lookup: one customer's latest orders with their items.
     */
    public const CUSTOMER_SQL = <<<'SQL'
SELECT o.id, o.region, o.order_date, oi.product_id, oi.quantity, oi.unit_price
FROM orders o
JOIN order_items oi ON oi.order_id = o.id
WHERE o.customer_id = ?
ORDER BY o.order_date DESC
LIMIT 50
SQL;

    public static function sleepSeconds(array $query): float
    {
        $ms = (int) ($query['ms'] ?? 100);

        return max(0, min($ms, 10_000)) / 1000;
    }

    public static function region(array $query): string
    {
        $region = (string) ($query['region'] ?? '');

        return \in_array($region, self::REGIONS, true)
            ? $region
            : self::REGIONS[array_rand(self::REGIONS)];
    }

    public static function customerId(array $query): int
    {
        $id = (int) ($query['id'] ?? 0);

        return $id > 0 ? $id : random_int(1, 3000);
    }

    /**
     * CPU-bound work with no I/O. Async gives no advantage here: a busy
     * event loop blocks every other request on the same worker.
     */
    public static function cpu(array $query): array
    {
        $rounds = max(1, min((int) ($query['n'] ?? 20_000), 1_000_000));
        $hash = 'volt';
        for ($i = 0; $i < $rounds; $i++) {
            $hash = hash('sha256', $hash);
        }

        return ['rounds' => $rounds, 'hash' => $hash];
    }

    public static function config(): array
    {
        return [
            'host' => getenv('DB_HOST') ?: 'postgres',
            'port' => (int) (getenv('DB_PORT') ?: 5432),
            'dbname' => getenv('DB_NAME') ?: 'analytics_db',
            'user' => getenv('DB_USER') ?: 'user',
            'password' => getenv('DB_PASSWORD') ?: 'pass',
        ];
    }
}
