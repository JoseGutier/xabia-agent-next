<?php

namespace Google\ApiCore\Transport\Grpc;

use Grpc\UnaryCall;

/**
 * Class ForwardingUnaryCall wraps a \Grpc\UnaryCall.
 *
 * @experimental
 */
class ForwardingUnaryCall extends ForwardingCall
{
    /** @var UnaryCall */
    protected object $innerCall;

    /**
     * Wait for the server to respond with data and a status.
     *
     * @return array [response data, status]
     */
    public function wait()
    {
        return $this->innerCall->wait();
    }
}
