<?php

declare(strict_types=1);

namespace Asyncopath;

use Amp\ByteStream\ReadableIterableStream;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketClientHandler;
use League\Route\Route;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

final class Router implements RequestHandler
{
    private bool $running = false;

    private \League\Route\Router $router;

    private WorkerLifecycle $lifecycle;

    /**
     * Native amphp handlers keyed by exact path. They bypass the PSR-7 bridge,
     * which can't carry a protocol upgrade such as a websocket handshake.
     *
     * @var array<string, RequestHandler>
     */
    private array $ampRoutes = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ErrorHandler $errorHandler,
    ) {
        $this->router = new \League\Route\Router;
        $this->lifecycle = new WorkerLifecycle;
    }

    /**
     * Route a request and dispatch it to the appropriate handler.
     */
    public function handleRequest(Request $request): Response
    {
        $ampHandler = $this->ampRoutes[$request->getUri()->getPath()] ?? null;
        if ($ampHandler !== null) {
            return $ampHandler->handleRequest($request);
        }

        $psr17Factory = new Psr17Factory;
        $psrRequest = Bridge::convertAmpToPsr7($request, $psr17Factory);

        $psrResponse = $this->router->dispatch($psrRequest);

        $iterable = (function () use ($psrResponse) {
            $stream = $psrResponse->getBody();
            $stream->rewind();
            if (! $stream->isReadable()) {
                $this->logger->info('Not readable');
                yield;
            }
            while (! $stream->eof()) {
                yield $stream->read(8192);
            }
        })();

        return new Response(
            $psrResponse->getStatusCode(),
            $psrResponse->getHeaders(),
            new ReadableIterableStream($iterable)
        );
    }

    public function addRoute(
        string|array $method,
        string $path,
        callable|array|string|RequestHandlerInterface $handler
    ): Route {
        if ($this->running) {
            throw new \Error(
                'Cannot add routes once the server has started'
            );
        }

        return $this->router->map($method, $path, $handler);
    }

    public function get(
        string $path,
        callable|array|string|RequestHandlerInterface $handler
    ) {
        return $this->router->map('GET', $path, $handler);
    }

    public function post(
        string $path,
        callable|array|string|RequestHandlerInterface $handler
    ) {
        return $this->router->map('POST', $path, $handler);
    }

    public function options(
        string $path,
        callable|array|string|RequestHandlerInterface $handler
    ) {
        return $this->router->map('OPTIONS', $path, $handler);
    }

    public function put(
        string $path,
        callable|array|string|RequestHandlerInterface $handler
    ) {
        return $this->router->map('PUT', $path, $handler);
    }

    public function delete(
        string $path,
        callable|array|string|RequestHandlerInterface $handler
    ) {
        return $this->router->map('DELETE', $path, $handler);
    }

    public function patch(
        string $path,
        callable|array|string|RequestHandlerInterface $handler
    ) {
        return $this->router->map('PATCH', $path, $handler);
    }

    /**
     * Accept websocket connections on $path and hand each client to $handler.
     */
    public function websocket(string $path, WebsocketClientHandler $handler): void
    {
        if ($this->running) {
            throw new \Error(
                'Cannot add routes once the server has started'
            );
        }

        $this->ampRoutes[$path] = new Websocket(
            httpServer: $this->lifecycle,
            logger: $this->logger,
            acceptor: new Rfc6455Acceptor,
            clientHandler: $handler,
        );
    }

    /**
     * Run shutdown hooks, e.g. close open websocket connections.
     */
    public function stop(): void
    {
        $this->lifecycle->stop();
    }

    public function middleware(MiddlewareInterface $middlewareInterface)
    {
        return $this->router->middleware($middlewareInterface);
    }
}
