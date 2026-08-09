<?php

namespace Google\ApiCore\Transport\Grpc;

use Grpc\AbstractCall;

/**
 * Class ForwardingCall wraps a \Grpc\AbstractCall.
 *
 * @experimental
 */
abstract class ForwardingCall
{
    /**
     * @var AbstractCall|ForwardingCall
     */
    protected object $innerCall;

    /**
     * ForwardingCall constructor.
     *
     * @param AbstractCall|ForwardingCall $innerCall
     */
    public function __construct($innerCall)
    {
        $this->innerCall = $innerCall;
    }

    /**
     * @return mixed The metadata sent by the server
     */
    public function getMetadata()
    {
        return $this->innerCall->getMetadata();
    }

    /**
     * @return mixed The trailing metadata sent by the server
     */
    public function getTrailingMetadata()
    {
        return $this->innerCall->getTrailingMetadata();
    }

    /**
     * @return string The URI of the endpoint
     */
    public function getPeer()
    {
        return $this->innerCall->getPeer();
    }

    /**
     * Cancels the call.
     */
    public function cancel()
    {
        $this->innerCall->cancel();
    }
}
