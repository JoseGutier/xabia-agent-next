<?php

namespace Google\ApiCore;

use Google\Auth\Logging\LoggingTrait;
use Google\Auth\Logging\RpcLogEvent;
use Google\Protobuf\Internal\Message;
use Google\Rpc\Code;
use Grpc\ClientStreamingCall;
use Psr\Log\LoggerInterface;

/**
 * ClientStream is the response object from a gRPC client streaming API call.
 */
class ClientStream
{
    use LoggingTrait;

    private $call;
    private null|LoggerInterface $logger;

    /**
     * ClientStream constructor.
     *
     * @param ClientStreamingCall $clientStreamingCall The gRPC client streaming call object
     * @param array $streamingDescriptor
     * @param null|LoggerInterface $logger A PSR-3 compliant logger.
     */
    public function __construct(
        ClientStreamingCall $clientStreamingCall,
        array $streamingDescriptor = [],
        null|LoggerInterface $logger = null,
    ) {
        $this->call = $clientStreamingCall;
        $this->logger = $logger;
    }

    /**
     * Write request to the server.
     *
     * @param mixed $request The request to write
     */
    public function write($request)
    {
        
        if ($this->logger && $request instanceof Message) {
            $requestEvent = new RpcLogEvent();

            $requestEvent->payload = $request->serializeToJsonString();
            $requestEvent->processId = (int) getmypid();
            $requestEvent->requestId = crc32((string) spl_object_id($this) . getmypid());

            $this->logRequest($requestEvent);
        }

        $this->call->write($request);
    }

    /**
     * Read the response from the server, completing the streaming call.
     *
     * @throws ApiException
     * @return mixed The response object from the server
     */
    public function readResponse()
    {
        list($response, $status) = $this->call->wait();
        if ($status->code == Code::OK) {
            if ($this->logger) {
                $responseEvent = new RpcLogEvent();

                $responseEvent->headers = $status->metadata;
                $responseEvent->status = $status->code;
                $responseEvent->processId = (int) getmypid();
                $responseEvent->requestId = crc32((string) spl_object_id($this) . getmypid());

                if ($response instanceof Message) {
                    $response->serializeToJsonString();
                }

                $this->logResponse($responseEvent);
            }

            return $response;
        } else {
            throw ApiException::createFromStdClass($status);
        }
    }

    /**
     * Write all data in $dataArray and read the response from the server, completing the streaming
     * call.
     *
     * @param mixed[] $requests An iterator of request objects to write to the server
     * @return mixed The response object from the server
     */
    public function writeAllAndReadResponse(array $requests)
    {
        foreach ($requests as $request) {
            $this->write($request);
        }
        return $this->readResponse();
    }

    /**
     * Return the underlying gRPC call object
     *
     * @return \Grpc\ClientStreamingCall|mixed
     */
    public function getClientStreamingCall()
    {
        return $this->call;
    }
}
