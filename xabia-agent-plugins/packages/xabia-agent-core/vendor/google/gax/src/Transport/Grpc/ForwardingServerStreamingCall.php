<?php

namespace Google\ApiCore\Transport\Grpc;

use Grpc\ServerStreamingCall;

/**
 * Class ForwardingServerStreamingCall wraps a \Grpc\ServerStreamingCall.
 *
 * @experimental
 */
class ForwardingServerStreamingCall extends ForwardingCall
{
    /** @var ServerStreamingCall */
    protected object $innerCall;

    /**
     * @return mixed An iterator of response values
     */
    public function responses()
    {
        return $this->innerCall->responses();
    }

    /**
     * Wait for the server to send the status, and return it.
     *
     * @return \stdClass The status object, with integer $code, string
     *                   $details, and array $metadata members
     */
    public function getStatus()
    {
        return $this->innerCall->getStatus();
    }
}
