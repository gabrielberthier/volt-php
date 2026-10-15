<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private array $allowedOrigins = ['*'],
        private array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        private array $allowedHeaders = ['Origin', 'Content-Type', 'Accept', 'Authorization'],
        private int $maxAge = 86400,
        private bool $allowCredentials = false
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');

        // 1. Intercept Preflight (OPTIONS) Requests
        if ($request->getMethod() === 'OPTIONS' && $request->hasHeader('Access-Control-Request-Method')) {
            $response = $this->responseFactory->createResponse(204); // 204 No Content

            return $this->addCorsHeaders($response, $origin);
        }

        // 2. Process the Actual Request
        $response = $handler->handle($request);

        // 3. Append CORS Headers to the resulting Response
        if ($origin !== '') {
            $response = $this->addCorsHeaders($response, $origin);
        }

        return $response;
    }

    private function addCorsHeaders(ResponseInterface $response, string $origin): ResponseInterface
    {
        // Resolve Allowed Origin
        $corsOrigin = '*';
        if (in_array($origin, $this->allowedOrigins, true) || in_array('*', $this->allowedOrigins, true)) {
            $corsOrigin = \in_array('*', $this->allowedOrigins, true) && ! $this->allowCredentials ? '*' : $origin;
        }

        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $corsOrigin)
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders))
            ->withHeader('Access-Control-Max-Age', (string) $this->maxAge);

        if ($this->allowCredentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}
