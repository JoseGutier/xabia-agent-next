<?php

namespace Google\ApiCore\Testing;

use Google\ApiCore\Transport\GrpcTransport;
use Grpc\ChannelCredentials;
use Psr\Log\LoggerInterface;

/**
 * @internal
 */
class MockGrpcTransport extends GrpcTransport
{
    private $requestArguments;
    private $mockCall;

    /**
     * @param mixed $mockCall
     */
    public function __construct($mockCall = null, ?LoggerInterface $logger = null)
    {
        $this->mockCall = $mockCall;
        $opts = ['credentials' => ChannelCredentials::createSsl()];
        parent::__construct('', $opts, logger: $logger);
    }

    /**
     * @param string $method
     * @param array $arguments
     * @param callable $deserialize
     */
    protected function _simpleRequest(
        $method,
        $arguments,
        $deserialize,
        array $metadata = [],
        array $options = []
    ) {
        $this->logCall($method, $deserialize, $metadata, $options, $arguments);
        return $this->mockCall;
    }

    /**
     * @param string $method
     * @param callable $deserialize
     */
    protected function _clientStreamRequest(
        $method,
        $deserialize,
        array $metadata = [],
        array $options = []
    ) {
        $this->logCall($method, $deserialize, $metadata, $options);
        return $this->mockCall;
    }

    /**
     * @param string $method
     * @param array $arguments
     * @param callable $deserialize
     */
    protected function _serverStreamRequest(
        $method,
        $arguments,
        $deserialize,
        array $metadata = [],
        array $options = []
    ) {
        $this->logCall($method, $deserialize, $metadata, $options, $arguments);
        return $this->mockCall;
    }

    /**
     * @param string $method
     * @param callable $deserialize
     */
    protected function _bidiRequest(
        $method,
        $deserialize,
        array $metadata = [],
        array $options = []
    ) {
        $this->logCall($method, $deserialize, $metadata, $options);
        return $this->mockCall;
    }

    /**
     * @param string $method
     * @param callable $deserialize
     * @param array $arguments
     */
    private function logCall(
        $method,
        $deserialize,
        array $metadata = [],
        array $options = [],
        $arguments = null
    ) {
        $this->requestArguments = [
            'method' => $method,
            'arguments' => $arguments,
            'deserialize' => $deserialize,
            'metadata' => $metadata,
            'options' => $options,
        ];
    }

    public function getRequestArguments()
    {
        return $this->requestArguments;
    }
}
