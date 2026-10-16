<?php

declare(strict_types=1);

namespace Asyncopath;

use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\HttpServerStatus;
use Amp\Http\Server\RequestHandler;

/**
 * Stand-in for the worker's HTTP server.
 *
 * PHPStreamServer keeps its SocketHttpServer private, but amphp components such
 * as Websocket need an HttpServer to register shutdown hooks on. The router is
 * built inside an already running worker, so this reports "started" and runs
 * the onStop hooks when the worker stops.
 */
final class WorkerLifecycle implements HttpServer
{
    private HttpServerStatus $status = HttpServerStatus::Started;

    /** @var list<\Closure(HttpServer):void> */
    private array $onStop = [];

    public function start(RequestHandler $requestHandler, ErrorHandler $errorHandler): void
    {
        throw new \Error('The worker process starts the HTTP server');
    }

    public function stop(): void
    {
        if ($this->status !== HttpServerStatus::Started) {
            return;
        }

        $this->status = HttpServerStatus::Stopping;
        foreach ($this->onStop as $onStop) {
            $onStop($this);
        }
        $this->status = HttpServerStatus::Stopped;
    }

    public function onStart(\Closure $onStart): void
    {
        // Already running: nothing will start again.
    }

    public function onStop(\Closure $onStop): void
    {
        $this->onStop[] = $onStop;
    }

    public function getStatus(): HttpServerStatus
    {
        return $this->status;
    }
}
