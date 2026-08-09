<?php

namespace Google\ApiCore\Testing;

use Google\ApiCore\ApiException;
use Google\Protobuf\Internal\Message;
use Google\Rpc\Code;
use Grpc;
use stdClass;

/**
 * The MockBidiStreamingCall class is used to mock out the \Grpc\BidiStreamingCall class
 * (https://github.com/grpc/grpc/blob/master/src/php/lib/Grpc/BidiStreamingCall.php)
 *
 * @internal
 */
class MockBidiStreamingCall extends Grpc\BidiStreamingCall
{
    use SerializationTrait;

    private $responses;
    private $status;
    private $writesDone = false;
    private $receivedWrites = [];

    /**
     * MockBidiStreamingCall constructor.
     * @param mixed[] $responses A list of response objects.
     * @param mixed|null $deserialize An optional deserialize method for the response object.
     * @param stdClass|null $status An optional status object. If set to null, a status of OK is used.
     */
    public function __construct(array $responses, $deserialize = null, ?stdClass $status = null)
    {
        $this->responses = $responses;
        $this->deserialize = $deserialize;
        if (is_null($status)) {
            $status = new MockStatus(Code::OK);
        }
        $this->status = $status;
    }

    /**
     * @return mixed|null
     * @throws ApiException
     */
    public function read()
    {
        if (count($this->responses) > 0) {
            $resp = array_shift($this->responses);
            if (is_null($resp)) {
                
                
                
                $this->responses = [];
                $this->writesDone();
                return null;
            }
            $obj = $this->deserializeMessage($resp, $this->deserialize);
            return $obj;
        } elseif ($this->writesDone) {
            return null;
        } else {
            throw new ApiException(
                'No more responses to read, but closeWrite() not called - '
                . 'this would be blocking',
                Grpc\STATUS_INTERNAL,
                null
            );
        }
    }

    /**
     * @return stdClass|null
     * @throws ApiException
     */
    public function getStatus()
    {
        if (count($this->responses) > 0) {
            throw new ApiException(
                'Calls to getStatus() will block if all responses are not read',
                Grpc\STATUS_INTERNAL,
                null
            );
        }
        if (!$this->writesDone) {
            throw new ApiException(
                'Calls to getStatus() will block if closeWrite() not called',
                Grpc\STATUS_INTERNAL,
                null
            );
        }
        return $this->status;
    }

    /**
     * Save the request object, to be retrieved via getReceivedCalls()
     * @param Message|mixed $request The request object
     * @param array $options An array of options.
     * @throws ApiException
     */
    public function write($request, array $options = [])
    {
        if ($this->writesDone) {
            throw new ApiException(
                'Cannot call write() after writesDone()',
                Grpc\STATUS_INTERNAL,
                null
            );
        }
        if (is_a($request, '\Google\Protobuf\Internal\Message')) {
            /** @var Message $newRequest */
            $newRequest = new $request();
            $newRequest->mergeFromString($request->serializeToString());
            $request = $newRequest;
        }
        $this->receivedWrites[] = $request;
    }

    /**
     * Set writesDone to true
     */
    public function writesDone()
    {
        $this->writesDone = true;
    }

    /**
     * Return a list of calls made to write(), and clear $receivedFuncCalls.
     *
     * @return mixed[] An array of received requests
     */
    public function popReceivedCalls()
    {
        $receivedFuncCallsTemp = $this->receivedWrites;
        $this->receivedWrites = [];
        return $receivedFuncCallsTemp;
    }
}
