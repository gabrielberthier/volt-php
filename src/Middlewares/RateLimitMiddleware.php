<?php

declare(strict_types=1);

namespace Asyncopath\Middlewares;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\SimpleCache\CacheInterface;

class RateLimitMiddleware implements MiddlewareInterface
{
    private CacheInterface $cache;

    private ResponseFactoryInterface $responseFactory;

    private int $maxRequests;

    private int $timeWindowSeconds;

    public function __construct(
        CacheInterface $cache,
        ResponseFactoryInterface $responseFactory,
        int $maxRequests = 60,
        int $timeWindowSeconds = 60
    ) {
        $this->cache = $cache;
        $this->responseFactory = $responseFactory;
        $this->maxRequests = $maxRequests;
        $this->timeWindowSeconds = $timeWindowSeconds;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // 1. Identify the client (using IP address in this basic example)
        $serverParams = $request->getServerParams();
        $ip = $serverParams['REMOTE_ADDR'] ?? 'unknown';
        $cacheKey = 'rate_limit_'.md5($ip);

        // 2. Get current request count from cache
        $currentRequests = (int) $this->cache->get($cacheKey, 0);

        // 3. Check if they exceeded the limit
        if ($currentRequests >= $this->maxRequests) {
            // Early Return: Block the request and return a 429 Too Many Requests
            $response = $this->responseFactory->createResponse(429);
            $response->getBody()->write('Too Many Requests');

            return $response
                ->withHeader('Retry-After', (string) $this->timeWindowSeconds)
                ->withHeader('X-RateLimit-Limit', (string) $this->maxRequests)
                ->withHeader('X-RateLimit-Remaining', '0');
        }

        // 4. Increment the count and save it to the cache
        $this->cache->set($cacheKey, $currentRequests + 1, $this->timeWindowSeconds);

        // 5. Pass the request deeper into the application
        $response = $handler->handle($request);

        // 6. Append rate limit headers to the successful outgoing response
        $remaining = $this->maxRequests - ($currentRequests + 1);

        return $response
            ->withHeader('X-RateLimit-Limit', (string) $this->maxRequests)
            ->withHeader('X-RateLimit-Remaining', (string) $remaining);
    }
}
