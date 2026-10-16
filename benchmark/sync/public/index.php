<?php

declare(strict_types=1);

/*
 * Synchronous benchmark server: classic PHP-FPM + PDO.
 *
 * Each FPM child handles exactly one request at a time. While a query is
 * running the child is blocked, so throughput is capped by pm.max_children.
 */

require __DIR__.'/../../../vendor/autoload.php';

use Asyncopath\Benchmark\Workloads;

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $c = Workloads::config();
        $pdo = new PDO(
            "pgsql:host={$c['host']};port={$c['port']};dbname={$c['dbname']}",
            $c['user'],
            $c['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Reuse one connection per FPM child: the sync stack's best case.
                PDO::ATTR_PERSISTENT => getenv('PDO_PERSISTENT') !== '0',
            ],
        );
    }

    return $pdo;
}

function fetchAll(string $sql, array $params): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function respond(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$query = $_GET;
$start = hrtime(true);

try {
    $data = match ($path) {
        '/health' => ['ok' => true],

        '/io' => fetchAll(Workloads::SLEEP_SQL, [Workloads::sleepSeconds($query)]),

        '/customer' => fetchAll(Workloads::CUSTOMER_SQL, [Workloads::customerId($query)]),

        '/report' => fetchAll(Workloads::REPORT_SQL, [Workloads::region($query)]),

        // Three heavy queries, one after another.
        '/regions' => (static function (): array {
            $out = [];
            foreach (Workloads::REGIONS as $region) {
                $out[$region] = fetchAll(Workloads::REPORT_SQL, [$region]);
            }

            return $out;
        })(),

        '/cpu' => Workloads::cpu($query),

        default => respond(404, ['error' => 'not found']),
    };
} catch (Throwable $e) {
    respond(500, ['error' => $e->getMessage()]);
}

respond(200, [
    'server' => 'sync',
    'pid' => getmypid(),
    'ms' => round((hrtime(true) - $start) / 1e6, 2),
    'data' => $data,
]);
