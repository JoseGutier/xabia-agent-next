<?php

namespace Google\ApiCore\Transport\Grpc;

use Google\ApiCore\ServerStreamingCallInterface;
use Grpc\Gcp\GCPServerStreamCall;
use Grpc\ServerStreamingCall;

/**
 * Class ServerStreamingCallWrapper implements \Google\ApiCore\ServerStreamingCallInterface.
 * This is essentially a wrapper class around the \Grpc\ServerStreamingCall.
 */
class ServerStreamingCallWrapper implements ServerStreamingCallInterface
{
    /**
     * @var ServerStreamingCall|GCPServerStreamCall
     */
    private object $stream;

    /**
     * @param ServerStreamingCall|GCPServerStreamCall $stream
     */
    public function __construct($stream)
    {
        $this->stream = $stream;
    }

    /**
     * {@inheritdoc}
     */
    public function start($data, array $metadata = [], array $callOptions = [])
    {
        $this->stream->start($data, $metadata, $callOptions);
    }

    /**
     * {@inheritdoc}
     */
    public function responses()
    {
        foreach ($this->stream->responses() as $response) {
            yield $response;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getStatus()
    {
        return $this->stream->getStatus();
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata()
    {
        return $this->stream->getMetadata();
    }

    /**
     * {@inheritdoc}
     */
    public function getTrailingMetadata()
    {
        return $this->stream->getTrailingMetadata();
    }

    /**
     * {@inheritdoc}
     */
    public function getPeer()
    {
        return $this->stream->getPeer();
    }

    /**
     * {@inheritdoc}
     */
    public function cancel()
    {
        $this->stream->cancel();
    }

    /**
     * {@inheritdoc}
     */
    public function setCallCredentials($call_credentials)
    {
        $this->stream->setCallCredentials($call_credentials);
    }
}
