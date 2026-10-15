<?php

use function Async\await_all_or_fail;
use function Async\spawn;

$start = microtime(true);

// 1. Establish PDO PostgreSQL Connection
$dsn = 'pgsql:host=host.docker.internal;port=5432;dbname=analytics_db;';
$user = 'user';
$password = 'pass';

try {
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_POOL_ENABLED => true,
        PDO::ATTR_POOL_MIN => 2,
        PDO::ATTR_POOL_MAX => 10,
    ]);
} catch (PDOException $e) {
    exit('Connection failed: '.$e->getMessage());
}

$regions = ['North America', 'Europe', 'Asia'];
$results = [];

$heavy_pg_sql_query = <<<'SQL'
WITH MonthlyCategoryStats AS (
    -- 1. First, handle the grouping and aggregations
    SELECT
        p.category,
        TO_CHAR(o.order_date, 'YYYY-MM') AS sales_month,
        COUNT(DISTINCT o.customer_id) AS unique_customers,
        SUM(oi.quantity * oi.unit_price) AS total_revenue
    FROM orders o
    JOIN order_items oi ON o.id = oi.order_id
    JOIN products p ON oi.product_id = p.id
    -- Postgres syntax for subtracting time
    WHERE o.order_date >= NOW() - INTERVAL '1 year'
    GROUP BY 
        p.category, 
        TO_CHAR(o.order_date, 'YYYY-MM')
)
-- 2. Then, apply the window functions
SELECT
    category,
    sales_month,
    unique_customers,
    total_revenue,
    -- Calculate a 3-month rolling average revenue per category
    AVG(total_revenue) OVER (
        PARTITION BY category
        ORDER BY sales_month
        ROWS BETWEEN 2 PRECEDING AND CURRENT ROW
    ) AS moving_avg_revenue,
    -- Rank categories by revenue within each month
    RANK() OVER (
        PARTITION BY sales_month
        ORDER BY total_revenue DESC
    ) AS category_rank_in_month
FROM MonthlyCategoryStats
ORDER BY 
    sales_month DESC, 
    total_revenue DESC;
SQL;

$futures = [];
// 2. Execute sequentially (BLOCKING)
foreach ($regions as $region) {
    // Inject region filter comment or parameter
    $query = "/* Region: $region */ ".$heavy_pg_sql_query;
    // $stmt = spawn(fn() => $pdo->query($query));
    $futures[] = spawn(function () use ($pdo, $query, $region) {
        $stmt = $pdo->query($query);

        return [$region => $stmt->fetchAll()];
    });
}

$resultsAwaited = await_all_or_fail($futures);

foreach ($resultsAwaited as $key => $value) {
    $results[$key] = $value;
}

$end = microtime(true);
echo 'Synchronous PDO (PostgreSQL) execution took: '.round($end - $start, 2)." seconds.\n";

$start = microtime(true);

/**
 * Saves string data to a file.
 *
 * @param  string  $filePath  The full path where the file should be saved.
 * @param  string  $content  The data to write to the file.
 * @param  bool  $append  If true, adds to the end of the file instead of overwriting.
 * @return bool True on success, False on failure.
 */
function saveDataToFile(string $filePath, string $content, bool $append = false): bool
{
    // Extract the directory path
    $directory = dirname($filePath);

    // Create the directory if it doesn't exist
    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    // Write the contents to the file
    $result = file_put_contents($filePath, $content);

    return $result !== false;
}

// --- USAGE EXAMPLES ---

// 1. Overwrite (or create) a file with new JSON data

$fileFutures = [];

foreach ($results as $region => $data) {
    $fileFutures[] = spawn(function () use ($region, $data) {
        saveDataToFile(__DIR__."/data/sync/export-{$region}.json", json_encode($data));
        $path = __DIR__."/data/sync/export-{$region}.json";
        echo "Saved {$path}";
    });
}

await_all_or_fail($fileFutures);

$end = microtime(true);

echo 'Synchronous File Saving (JSON) execution took: '.round($end - $start, 4)." seconds.\n";

// Output: Synchronous PDO (PostgreSQL) execution took: 6.05 seconds.
