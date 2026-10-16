<?php

require __DIR__.'/../vendor/autoload.php';

use Asyncopath\RouterSingleton;
use PHPStreamServer\Core\Server;
use PHPStreamServer\Core\Worker\WorkerProcess;
use PHPStreamServer\Plugin\FileMonitor\FileMonitorPlugin;
use PHPStreamServer\Plugin\FileMonitor\WatchDir;
use PHPStreamServer\Plugin\HttpServer\HttpServerPlugin;
use PHPStreamServer\Plugin\HttpServer\ReloadStrategy\MaxRequestsReloadStrategy;
use PHPStreamServer\Plugin\HttpServer\Worker\HttpServerProcess;

$routerSettings = require __DIR__.'/router.php';

$server = new Server;

$server->addPlugin(
    new HttpServerPlugin
        // Optional. HttpServerPlugin configuration
    ,
);

$server->addPlugin(
    new FileMonitorPlugin(
        new WatchDir(
            sourceDir: __DIR__,
            filePattern: ['*'],
            recursive: true,
            invalidateOpcache: true
        ),
    ),
);

$currentTime = time();

$router = new RouterSingleton;

$server->addWorker(
    new HttpServerProcess(
        // HttpServerProcess configuration
        name: 'Web Server', // Worker name
        listen: '0.0.0.0:8080', // Address to listen on
        // One worker: the whiteboard keeps its board and websocket clients in
        // this process's memory, so every client must land on the same worker.
        count: 1,
        onRequest: $router->handleRequest(...),
        reloadStrategies: [
            new MaxRequestsReloadStrategy(100),
        ],
        onStart: function (WorkerProcess $worker) use ($router): void {
            $currentTime = time();
            $router->restartRouter();
            $worker->logger->notice("Worker process has started at $currentTime");
        },
        onStop: function () use ($router): void {
            // Close websocket connections cleanly before the worker exits.
            $router->stop();
        },
    ),
);

exit($server->run());
