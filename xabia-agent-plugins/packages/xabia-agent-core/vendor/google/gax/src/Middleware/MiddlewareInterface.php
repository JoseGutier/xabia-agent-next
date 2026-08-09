<?php

namespace Google\ApiCore\Middleware;

use Google\ApiCore\BidiStream;
use Google\ApiCore\Call;
use Google\ApiCore\ClientStream;
use Google\ApiCore\ServerStream;
use GuzzleHttp\Promise\PromiseInterface;

/**
 * Middlewares must take a MiddlewareInterface as their first constructor
 * argument {@see Google\ApiCore\Middleware\ResponseMetadataMiddleware}, which
 * represents the next middleware in the chain. This next middleware MUST be
 * invoked by this MiddlewareInterface, and the result must be returned as part
 * of the `__invoke` method implementation.
 *
 * To create your own middleware, first implement the interface, as well as pass the handler
 * in through the constructor:
 *
 * ```
 * use Google\ApiCore\Call;
 * use Google\ApiCore\Middleware\MiddlewareInterface;
 *
 * class MyTestMiddleware implements MiddlewareInterface
 * {
 *     public function __construct(MiddlewareInterface $handler)
 *      {
 *.         $this->handler = $handler;
 *      }
 *      public function __invoke(Call $call, array $options)
 *      {
 *          echo "Logging info about the call: " . $call->getMethod();
 *          return ($this->handler)($call, $options);
 *      }
 * }
 * ```
 *
 * Next, add the middleware to any class implementing `GapicClientTrait` by passing in a
 * callable which returns the new middleware:
 *
 * ```
 * $client = new ExampleGoogleApiServiceClient();
 * $client->addMiddleware(function (MiddlewareInterface $handler) {
 *     return new MyTestMiddleware($handler);
 * });
 * ```
 */
interface MiddlewareInterface
{
    /**
     * Modify or observe the API call request and response.
     * The returned value must include the result of the next MiddlewareInterface invocation in the
     * chain.
     *
     * @param Call $call
     * @param array $options
     * @return PromiseInterface|ClientStream|ServerStream|BidiStream
     */
    public function __invoke(Call $call, array $options);
}
