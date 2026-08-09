<?php

namespace Google\ApiCore;

use Google\Auth\Logging\LoggingTrait;
use Google\Auth\Logging\RpcLogEvent;
use Google\Protobuf\Internal\Message;
use Google\Rpc\Code;
use Psr\Log\LoggerInterface;

/**
 * ServerStream is the response object from a server streaming API call.
 *
 * @template T = mixed
 */
class ServerStream
{
    use LoggingTrait;

    private $call;
    private $resourcesGetMethod;
    private null|LoggerInterface $logger;

    /**
     * ServerStream constructor.
     *
     * @param ServerStreamingCallInterface $serverStreamingCall The server streaming call object
     * @param array $streamingDescriptor
     * @param null|LoggerInterface $logger A PSR-3 compliant logger.
     */
    public function __construct(
        $serverStreamingCall,
        array $streamingDescriptor = [],
        null|LoggerInterface $logger = null
    ) {
        $this->call = $serverStreamingCall;
        if (array_key_exists('resourcesGetMethod', $streamingDescriptor)) {
            $this->resourcesGetMethod = $streamingDescriptor['resourcesGetMethod'];
        }
        $this->logger = $logger;
    }

    /**
     * A generator which yields results from the server until the streaming call
     * completes. Throws an ApiException if the streaming call failed.
     *
     * @throws ApiException
     * @return \Generator<int, T>|mixed
     */
    public function readAll()
    {
        $resourcesGetMethod = $this->resourcesGetMethod;
        foreach ($this->call->responses() as $response) {
            if ($this->logger && $response instanceof Message) {
                $responseEvent = new RpcLogEvent();
                $responseEvent->payload = $response->serializeToJsonString();
                $responseEvent->processId = (int) getmypid();
                $responseEvent->requestId = crc32((string) spl_object_id($this) . getmypid());

                $this->logResponse($responseEvent);
            }

            if (!is_null($resourcesGetMethod)) {
                foreach ($response->$resourcesGetMethod() as $resource) {
                    yield $resource;
                }
            } else {
                yield $response;
            }
        }

        
        
        $status = $this->call->getStatus();

        if ($this->logger) {
            $statusEvent = new RpcLogEvent();
            $statusEvent->status = $status->code;
            $statusEvent->processId = (int) getmypid();
            $statusEvent->requestId = crc32((string) spl_object_id($this) . getmypid());

            $this->logResponse($statusEvent);
        }

        if ($status->code !== Code::OK) {
            throw ApiException::createFromStdClass($status);
        }
    }

    /**
     * Return the underlying call object.
     *
     * @return ServerStreamingCallInterface
     */
    public function getServerStreamingCall()
    {
        return $this->call;
    }
}
