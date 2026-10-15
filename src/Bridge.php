<?php

namespace Asyncopath;

use Amp\Http\Server\Request as AmpRequest;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;

class Bridge
{
    /**
     * Converts an AMPHP v3 Request to a PSR-7 ServerRequest.
     */
    public static function convertAmpToPsr7(
        AmpRequest $ampRequest,
        ServerRequestFactoryInterface $requestFactory,
    ): ServerRequestInterface {
        $psrRequest = $requestFactory->createServerRequest(
            $ampRequest->getMethod(),
            (string) $ampRequest->getUri()
        );

        // 2. Transfer Headers
        foreach ($ampRequest->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $psrRequest = $psrRequest->withAddedHeader($name, $value);
            }
        }

        // 3. Set Protocol Version
        $psrRequest = $psrRequest->withProtocolVersion($ampRequest->getProtocolVersion());

        // 4. Wrap the Body (Lazy Loading)
        // Here we use our custom wrapper instead of buffering it into memory.
        $psrStream = new AmpReadableStreamWrapper($ampRequest->getBody());
        $psrRequest = $psrRequest->withBody($psrStream);

        // 5. Transfer Query Parameters
        $queryString = $ampRequest->getUri()->getQuery();
        if (! empty($queryString)) {
            parse_str($queryString, $queryParams);
            $psrRequest = $psrRequest->withQueryParams($queryParams);
        }

        // 6. Transfer Cookies
        $cookies = [];
        foreach ($ampRequest->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $psrRequest->withCookieParams($cookies);
    }
}
