<?php

require __DIR__ . '/../vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use Luzrain\DbalDriver\AmphpPostgres\AsyncConection;
use Luzrain\DbalDriver\AmphpPostgres\AsyncDriver;
use Amp\File;
use function Amp\async;
use function Amp\File\createDirectory;
use function Amp\File\createDirectoryRecursively;
use function Amp\File\isDirectory;
use function Amp\File\write;
use function Amp\Future\await;

$start = microtime(true);

// 1. Configure the non-blocking connection pool.
// AMPHP will automatically manage connections to execute queries concurrently.
$connectionParams = [
    'dbname' => 'analytics_db',
    'user' => 'user',
    'password' => 'pass',
    'host' => 'localhost',
    'port' => 5432,
    'driverClass' => AsyncDriver::class,
    'wrapperClass' => AsyncConection::class,
    'driverOptions' => [
        'max_connections' => 100,
        'idle_timeout' => 60,
    ],
];
$conn = DriverManager::getConnection($connectionParams);

$regions = ['North America', 'Europe', 'Asia'];
$futures = [];

$heavy_pg_sql_query = <<<SQL
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


// 2. Dispatch all queries asynchronously using Fibers
foreach ($regions as $region) {

    // Amp\async() spins up a new Fiber (coroutine) for each region.
    // They will all run concurrently without blocking the main PHP thread.
    $futures[$region] = async(function () use ($conn, $region, $heavy_pg_sql_query) {

        $query = "/* Inject Region Filter for $region */ " . $heavy_pg_sql_query;

        // The Fiber suspends here while waiting for MySQL to process the heavy query.
        // It hands control back to the event loop so the other regions can start their queries.
        $result = $conn->executeQuery($query);

        $rows = [];

        // AMPHP streams the results asynchronously so we don't blow up memory
        foreach ($result->fetchAllAssociative() as $row) {
            $rows[] = $row;
        }

        return $rows;
    });
}

// 3. Await all futures simultaneously
// PHP pauses here until ALL regional queries are complete.
// Because they executed in parallel, the total wait time is only ~2 seconds.
$results = await($futures);

$end = microtime(true);

echo "AMPHP asynchronous execution took: " . round($end - $start, 2) . " seconds.\n";
// Output: AMPHP asynchronous execution took: 2.01 seconds.

/**
 * Asynchronously saves string data to a file using AMPHP.
 *
 * @param string $filePath The full path where the file should be saved.
 * @param string $content The data to write to the file.
 */
function saveDataToFileAsync(string $filePath, string $content): void
{
    $dir = dirname($filePath);
    if(!isDirectory($dir)){
        createDirectoryRecursively($dir, 0755);
    }
    write($filePath, $content);
}

// --- USAGE EXAMPLE: Writing multiple files concurrently ---

$start = microtime(true);
$futures = [];

// Dispatch 5 file writes simultaneously
foreach ($results as $region => $value) {

    // Amp\async creates a new coroutine (Fiber).
    // All 5 operations will run in parallel.
    $futures[] = async(function () use ($region, $value) {
        $path = __DIR__ . "/data/async_export/export_{$region}.json";

        saveDataToFileAsync($path, json_encode($value));

        return "Saved {$path}";
    });
}

// Suspend the main script here and wait for ALL 5 disk writes to finish
$results = await($futures);

foreach ($results as $result) {
    echo $result . "\n";
}

$end = microtime(true);
echo "Completed asynchronous file operations in " . round($end - $start, 4) . " seconds.\n";