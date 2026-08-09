<?php

namespace Google\ApiCore;

use Google\Auth\Logging\LoggingTrait;
use Google\Auth\Logging\RpcLogEvent;
use Google\Protobuf\Internal\Message;
use Google\Rpc\Code;
use Grpc\BidiStreamingCall;
use Psr\Log\LoggerInterface;

/**
 * BidiStream is the response object from a gRPC bidirectional streaming API call.
 */
class BidiStream
{
    use LoggingTrait;

    private $call;
    private $isComplete = false;
    private $writesClosed = false;
    private $resourcesGetMethod = null;
    private $pendingResources = [];
    private null|LoggerInterface $logger = null;

    /**
     * BidiStream constructor.
     *
     * @param BidiStreamingCall $bidiStreamingCall The gRPC bidirectional streaming call object
     * @param array $streamingDescriptor
     * @param null|LoggerInterface $logger
     */
    public function __construct(
        BidiStreamingCall $bidiStreamingCall,
        array $streamingDescriptor = [],
        null|LoggerInterface $logger = null,
    ) {
        $this->call = $bidiStreamingCall;
        if (array_key_exists('resourcesGetMethod', $streamingDescriptor)) {
            $this->resourcesGetMethod = $streamingDescriptor['resourcesGetMethod'];
        }
        $this->logger = $logger;
    }

    /**
     * Write request to the server.
     *
     * @param mixed $request The request to write
     * @throws ValidationException
     */
    public function write($request)
    {
        if ($this->isComplete) {
            throw new ValidationException('Cannot call write() after streaming call is complete.');
        }
        if ($this->writesClosed) {
            throw new ValidationException('Cannot call write() after calling closeWrite().');
        }

        if ($this->logger && $request instanceof Message) {
            $logEvent = new RpcLogEvent();

            $logEvent->headers = null;
            $logEvent->payload = $request->serializeToJsonString();
            $logEvent->processId = (int) getmypid();
            $logEvent->requestId = crc32((string) spl_object_id($this) . getmypid());

            $this->logRequest($logEvent);
        }

        $this->call->write($request);
    }

    /**
     * Write all requests in $requests.
     *
     * @param iterable $requests An Iterable of request objects to write to the server
     *
     * @throws ValidationException
     */
    public function writeAll($requests = [])
    {
        foreach ($requests as $request) {
            $this->write($request);
        }
    }

    /**
     * Inform the server that no more requests will be written. The write() function cannot be
     * called after closeWrite() is called.
     * @throws ValidationException
     */
    public function closeWrite()
    {
        if ($this->isComplete) {
            throw new ValidationException(
                'Cannot call closeWrite() after streaming call is complete.'
            );
        }
        if (!$this->writesClosed) {
            $this->call->writesDone();
            $this->writesClosed = true;
        }
    }

    /**
     * Read the next response from the server. Returns null if the streaming call completed
     * successfully. Throws an ApiException if the streaming call failed.
     *
     * @throws ValidationException
     * @throws ApiException
     * @return mixed
     */
    public function read()
    {
        if ($this->isComplete) {
            throw new ValidationException('Cannot call read() after streaming call is complete.');
        }
        $resourcesGetMethod = $this->resourcesGetMethod;
        if (!is_null($resourcesGetMethod)) {
            if (count($this->pendingResources) === 0) {
                $response = $this->call->read();
                if (!is_null($response)) {
                    $pendingResources = [];
                    foreach ($response->$resourcesGetMethod() as $resource) {
                        $pendingResources[] = $resource;
                    }
                    $this->pendingResources = array_reverse($pendingResources);
                }
            }
            $result = array_pop($this->pendingResources);
        } else {
            $result = $this->call->read();
        }
        if (is_null($result)) {
            $status = $this->call->getStatus();
            $this->isComplete = true;
            if (!($status->code == Code::OK)) {
                throw ApiException::createFromStdClass($status);
            }
        }

        if ($this->logger) {
            $responseEvent = new RpcLogEvent();

            $responseEvent->headers = $this->call->getMetadata();
            $responseEvent->status = $status->code ?? null;
            $responseEvent->processId = (int) getmypid();
            $responseEvent->requestId = crc32((string) spl_object_id($this) . getmypid());

            if ($result instanceof Message) {
                $responseEvent->payload = $result->serializeToJsonString();
            }

            $this->logResponse($responseEvent);
        }

        return $result;
    }

    /**
     * Call closeWrite(), and read all responses from the server, until the streaming call is
     * completed. Throws an ApiException if the streaming call failed.
     *
     * @throws ValidationException
     * @throws ApiException
     * @return \Generator|mixed[]
     */
    public function closeWriteAndReadAll()
    {
        $this->closeWrite();
        $response = $this->read();
        while (!is_null($response)) {
            yield $response;
            $response = $this->read();
        }
    }

    /**
     * Return the underlying gRPC call object
     *
     * @return \Grpc\BidiStreamingCall|mixed
     */
    public function getBidiStreamingCall()
    {
        return $this->call;
    }
}
