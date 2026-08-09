<?php

namespace Google\ApiCore\Testing;

use Google\ApiCore\ApiException;
use Google\ApiCore\BidiStream;
use Google\ApiCore\Call;
use Google\ApiCore\ClientStream;
use Google\ApiCore\ServerStream;
use Google\ApiCore\Transport\TransportInterface;
use Google\Rpc\Code;
use GuzzleHttp\Promise\Promise;

/**
 * @internal
 */
class MockTransport implements TransportInterface
{
    use MockStubTrait;

    private $agentHeaderDescriptor; 

    public function setAgentHeaderDescriptor($agentHeaderDescriptor)
    {
        $this->agentHeaderDescriptor = $agentHeaderDescriptor;
    }

    public function startUnaryCall(Call $call, array $options)
    {
        $call = call_user_func([$this, $call->getMethod()], $call, $options);
        return $promise = new Promise(
            function () use ($call, &$promise) {
                list($response, $status) = $call->wait();

                if ($status->code == Code::OK) {
                    $promise->resolve($response);
                } else {
                    throw ApiException::createFromStdClass($status);
                }
            },
            [$call, 'cancel']
        );
    }

    public function startBidiStreamingCall(Call $call, array $options)
    {
        $newArgs = ['/' . $call->getMethod(), $this->deserialize, $options, $options];
        $response = $this->_bidiRequest(...$newArgs);
        return new BidiStream($response, $call->getDescriptor());
    }

    public function startClientStreamingCall(Call $call, array $options)
    {
        $newArgs = ['/' . $call->getMethod(), $this->deserialize, $options, $options];
        $response = $this->_clientStreamRequest(...$newArgs);
        return new ClientStream($response, $call->getDescriptor());
    }

    public function startServerStreamingCall(Call $call, array $options)
    {
        $newArgs = ['/' . $call->getMethod(), $call->getMessage(), $this->deserialize, $options, $options];
        $response = $this->_serverStreamRequest(...$newArgs);
        return new ServerStream($response, $call->getDescriptor());
    }

    public function __call(string $name, array $arguments)
    {
        $call = $arguments[0];
        $options = $arguments[1];
        $decode = $call->getDecodeType() ? [$call->getDecodeType(), 'decode'] : null;
        return $this->_simpleRequest(
            '/' . $call->getMethod(),
            $call->getMessage(),
            $decode,
            isset($options['headers']) ? $options['headers'] : [],
            $options
        );
    }

    public function close()
    {
        
    }
}
