<?php

declare(strict_types=1);

namespace Asyncopath;

use Amp\ByteStream\ReadableIterableStream;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use League\Route\Route;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

final class Router implements RequestHandler
{
    private bool $running = false;

    private \League\Route\Router $router;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ErrorHandler $errorHandler,
    ) {
        $this->router = new \League\Route\Router;
    }

    /**
     * Route a request and dispatch it to the appropriate handler.
     */
    public function handleRequest(Request $request): Response
    {
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
                // Read a chunk of data
                $chunk = $stream->read(32);
                // Process the chunk (e.g., echo it, write to another stream, etc.)
                $this->logger->info($chunk);
                yield $chunk;
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

    public function middleware(MiddlewareInterface $middlewareInterface)
    {
        return $this->router->middleware($middlewareInterface);
    }
}
