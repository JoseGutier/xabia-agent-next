<?php

namespace Grpc\Gcp;

/**
 * Represents an active call that sends a single message and then gets a
 * stream of responses.
 */
class GCPServerStreamCall extends GcpBaseCall
{
    private $response = null;

    protected function createRealCall($channel)
    {
        $this->real_call = new \Grpc\ServerStreamingCall($channel, $this->method, $this->deserialize, $this->options);
        $this->has_real_call = true;
        return $this->real_call;
    }

    /**
     * Pick a channel and start the call.
     *
     * @param mixed $data     The data to send
     * @param array $metadata Metadata to send with the call, if applicable
     *                        (optional)
     * @param array $options  An array of options, possible keys:
     *                        'flags' => a number (optional)
     */
    public function start($argument, $metadata, $options)
    {
        $channel_ref = $this->_rpcPreProcess($argument);
        $this->createRealCall($channel_ref->getRealChannel(
            $this->gcp_channel->credentials));
        $this->real_call->start($argument, $metadata, $options);
    }

    /**
     * @return mixed An iterator of response values
     */
    public function responses()
    {
        $response = $this->real_call->responses();
        
        
        
        
        if ($response) {
            $this->response = $response;
        }
        return $response;
    }

    /**
     * Wait for the server to send the status, and return it.
     *
     * @return \stdClass The status object, with integer $code, string
     *                   $details, and array $metadata members
     */
    public function getStatus()
    {
        $status = $this->real_call->getStatus();
        $this->_rpcPostProcess($status, $this->response);
        return $status;
    }

    /**
     * @return mixed The metadata sent by the server
     */
    public function getMetadata()
    {
        return $this->real_call->getMetadata();
    }
}
