<?php

declare(strict_types=1);

/*
 * Asynchronous benchmark server: amphp/http-server + amphp/postgres.
 *
 * Each worker process runs an event loop. While a query is in flight the
 * request's fiber is suspended and the worker keeps serving other requests,
 * so one worker can have many queries outstanding at once.
 *
 * Workers are plain forks sharing the port via SO_REUSEPORT. PHPStreamServer
 * is not used here because it never sets TCP_NODELAY, which adds a ~40 ms
 * delayed-ACK stall to every keep-alive response and would skew the results.
 */

require __DIR__.'/../../vendor/autoload.php';

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Driver\SocketClientFactory;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use Amp\Socket\BindContext;
use Amp\Socket\ResourceServerSocketFactory;
use Asyncopath\Benchmark\Workloads;
use Psr\Log\NullLogger;

use function Amp\async;
use function Amp\Future\await;
use function Amp\trapSignal;

$pool = null;

function fetchAll(PostgresConnectionPool $pool, string $sql, array $params): array
{
    $rows = [];
    foreach ($pool->execute($sql, $params) as $row) {
        $rows[] = $row;
    }

    return $rows;
}

function json(int $status, array $body): Response
{
    return new Response($status, ['content-type' => 'application/json'], json_encode($body));
}

$handler = static function (Request $request) use (&$pool): Response {
    $uri = $request->getUri();
    parse_str($uri->getQuery(), $query);
    $start = hrtime(true);

    try {
        $data = match ($uri->getPath()) {
            '/health' => ['ok' => true],

            '/io' => fetchAll($pool, Workloads::SLEEP_SQL, [Workloads::sleepSeconds($query)]),

            '/customer' => fetchAll($pool, Workloads::CUSTOMER_SQL, [Workloads::customerId($query)]),

            '/report' => fetchAll($pool, Workloads::REPORT_SQL, [Workloads::region($query)]),

            // Three heavy queries dispatched concurrently on separate pool connections.
            '/regions' => await(array_combine(
                Workloads::REGIONS,
                array_map(
                    static fn (string $region) => async(fetchAll(...), $pool, Workloads::REPORT_SQL, [$region]),
                    Workloads::REGIONS,
                ),
            )),

            '/cpu' => Workloads::cpu($query),

            default => null,
        };
    } catch (Throwable $e) {
        return json(500, ['error' => $e->getMessage()]);
    }

    if ($data === null) {
        return json(404, ['error' => 'not found']);
    }

    return json(200, [
        'server' => 'async',
        'pid' => getmypid(),
        'ms' => round((hrtime(true) - $start) / 1e6, 2),
        'data' => $data,
    ]);
};

function runWorker(Closure $handler, ?PostgresConnectionPool &$pool): void
{
    $c = Workloads::config();
    $pool = new PostgresConnectionPool(
        PostgresConfig::fromString(sprintf(
            'host=%s port=%d dbname=%s user=%s password=%s',
            $c['host'], $c['port'], $c['dbname'], $c['user'], $c['password'],
        )),
        maxConnections: (int) (getenv('DB_POOL_SIZE') ?: 64),
    );

    // No connection or concurrency limits, matching the nginx + FPM stack.
    $logger = new NullLogger;
    $server = new SocketHttpServer($logger, new ResourceServerSocketFactory, new SocketClientFactory($logger));
    $server->expose('0.0.0.0:8080', (new BindContext)->withReusePort()->withTcpNoDelay());
    $server->start(new ClosureRequestHandler($handler), new DefaultErrorHandler);

    trapSignal([SIGINT, SIGTERM]);
    $server->stop();
}

// Fork before anything touches the event loop: each worker gets its own loop and pool.
$workers = [];
for ($i = 0, $n = (int) (getenv('ASYNC_WORKERS') ?: 2); $i < $n; $i++) {
    $pid = pcntl_fork();
    if ($pid === 0) {
        runWorker($handler, $pool);
        exit(0);
    }
    $workers[] = $pid;
}

fwrite(STDOUT, sprintf("Async server listening on :8080 with %d workers\n", count($workers)));

pcntl_async_signals(true);
foreach ([SIGINT, SIGTERM] as $signal) {
    pcntl_signal($signal, static function () use (&$workers): void {
        foreach ($workers as $pid) {
            posix_kill($pid, SIGTERM);
        }
    });
}

// If any worker dies, take the whole container down so it is noticed.
$exit = 0;
while (($pid = pcntl_wait($status)) > 0) {
    $workers = array_diff($workers, [$pid]);
    if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
        $exit = 1;
        foreach ($workers as $other) {
            posix_kill($other, SIGTERM);
        }
    }
}

exit($exit);
