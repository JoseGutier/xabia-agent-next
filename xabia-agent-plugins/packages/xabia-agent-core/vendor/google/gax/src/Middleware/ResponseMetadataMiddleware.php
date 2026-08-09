<?php

namespace Google\ApiCore\Middleware;

use Google\ApiCore\Call;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;

/**
 * Middleware which transforms $response into [$response, $metadata]
 *
 * @internal
 */
class ResponseMetadataMiddleware implements MiddlewareInterface
{
    /** @var callable */
    private $nextHandler;

    /**
     * @param callable $nextHandler
     */
    public function __construct(callable $nextHandler)
    {
        $this->nextHandler = $nextHandler;
    }

    public function __invoke(Call $call, array $options)
    {
        $metadataReceiver = new Promise();
        $options['metadataCallback'] = function ($metadata) use ($metadataReceiver) {
            $metadataReceiver->resolve($metadata);
        };
        $next = $this->nextHandler;
        return $next($call, $options)->then(
            function ($response) use ($metadataReceiver) {
                if ($metadataReceiver->getState() === PromiseInterface::FULFILLED) {
                    return [$response, $metadataReceiver->wait()];
                } else {
                    return [$response, []];
                }
            }
        );
    }
}
