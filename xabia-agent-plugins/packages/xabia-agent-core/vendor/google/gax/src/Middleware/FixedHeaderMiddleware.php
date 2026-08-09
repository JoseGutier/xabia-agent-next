<?php

namespace Google\ApiCore\Middleware;

use Google\ApiCore\Call;

/**
 * Middleware to add fixed headers to an API call.
 *
 * @internal
 */
class FixedHeaderMiddleware implements MiddlewareInterface
{
    /** @var callable */
    private $nextHandler;
    private array $headers;
    private bool $overrideUserHeaders;

    public function __construct(
        callable $nextHandler,
        array $headers,
        bool $overrideUserHeaders = false
    ) {
        $this->nextHandler = $nextHandler;
        $this->headers = $headers;
        $this->overrideUserHeaders = $overrideUserHeaders;
    }

    public function __invoke(Call $call, array $options)
    {
        $userHeaders = $options['headers'] ?? [];
        if ($this->overrideUserHeaders) {
            $options['headers'] = $this->headers + $userHeaders;
        } else {
            $options['headers'] = $userHeaders + $this->headers;
        }

        $next = $this->nextHandler;
        return $next(
            $call,
            $options
        );
    }
}
