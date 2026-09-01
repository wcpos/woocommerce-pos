<?php

declare (strict_types=1);
namespace WCPOS\Vendor\Sentry\Integration;

use WCPOS\Vendor\GuzzleHttp\Psr7\ServerRequest;
use WCPOS\Vendor\Psr\Http\Message\ServerRequestInterface;
/**
 * Default implementation for RequestFetcherInterface. Creates a request object
 * from the PHP superglobals.
 */
final class RequestFetcher implements RequestFetcherInterface
{
    /**
     * {@inheritdoc}
     */
    public function fetchRequest() : ?ServerRequestInterface
    {
        if (!isset($_SERVER['REQUEST_METHOD']) || \PHP_SAPI === 'cli') {
            return null;
        }
        try {
            return ServerRequest::fromGlobals();
        } catch (\InvalidArgumentException $e) {
            return null;
        }
    }
}
