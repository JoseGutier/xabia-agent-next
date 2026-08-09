<?php

namespace Google\ApiCore\Testing;

use Google\ApiCore\ApiException;
use Google\ApiCore\ApiStatus;
use Google\Protobuf\Internal\Message;
use Google\Rpc\Code;
use Grpc;
use stdClass;

/**
 * The MockClientStreamingCall class is used to mock out the \Grpc\ClientStreamingCall class
 * (https://github.com/grpc/grpc/blob/master/src/php/lib/Grpc/ClientStreamingCall.php)
 *
 * The MockClientStreamingCall object is constructed with a response object, an optional deserialize
 * method, and an optional status. The response object and status are returned immediately from the
 * wait() method. It also provides a write() method that accepts request objects, and a
 * getAllRequests() method that returns all request objects passed to write(), and clears them.
 *
 * @internal
 */
class MockClientStreamingCall extends Grpc\ClientStreamingCall
{
    private $mockUnaryCall;
    private $waitCalled = false;
    private $receivedWrites = [];

    /**
     * MockClientStreamingCall constructor.
     * @param Message|string $response The response object.
     * @param callable|array|null $deserialize An optional deserialize method for the response object.
     * @param stdClass|null $status An optional status object. If set to null, a status of OK is used.
     */
    public function __construct($response, $deserialize = null, ?stdClass $status = null)
    {
        $this->mockUnaryCall = new MockUnaryCall($response, $deserialize, $status);
    }

    /**
     * Immediately return the preset response object and status.
     * @return array The response object and status.
     */
    public function wait()
    {
        $this->waitCalled = true;
        return $this->mockUnaryCall->wait();
    }

    /**
     * Save the request object, to be retrieved via getReceivedCalls()
     * @param Message|mixed $request The request object
     * @param array $options An array of options
     * @throws ApiException
     */
    public function write($request, array $options = [])
    {
        if ($this->waitCalled) {
            throw new ApiException('Cannot call write() after wait()', Code::INTERNAL, ApiStatus::INTERNAL);
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
