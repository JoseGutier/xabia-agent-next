<?php

namespace Google\ApiCore\Middleware;

use Google\ApiCore\Call;
use Google\ApiCore\Transport\TransportInterface;

/**
 * A Middleware in charge of handling the end of the callstack to call the transport layer.
 * This middleware is made so the callstack in the GapicClientTrait is always a middleware.
 *
 * @internal
 */
class TransportCallMiddleware implements MiddlewareInterface
{
    
    public function __construct(
        private TransportInterface $transport,
        private array $transportCallMethods
    ) {
    }

    public function __invoke(Call $call, array $options)
    {
        $startCallMethod = $this->transportCallMethods[$call->getCallType()];
        return $this->transport->$startCallMethod($call, $options);
    }
}
