<?php

namespace Google\ApiCore\Testing;

use Google\Protobuf\Internal\Message;
use Google\Rpc\Code;
use stdClass;

/**
 * The MockUnaryCall class is used to mock out the \Grpc\UnaryCall class
 * (https://github.com/grpc/grpc/blob/master/src/php/lib/Grpc/UnaryCall.php)
 *
 * The MockUnaryCall object is constructed with a response object, an optional deserialize
 * method, and an optional status. The response object and status are returned immediately from the
 * wait() method.
 *
 * @internal
 */
class MockUnaryCall extends \Grpc\UnaryCall
{
    use SerializationTrait;

    private $response;
    private $status;

    /**
     * MockUnaryCall constructor.
     * @param Message|string|null $response The response object.
     * @param callable|array|null $deserialize An optional deserialize method for the response object.
     * @param stdClass|null $status An optional status object. If set to null, a status of OK is used.
     */
    public function __construct($response = null, $deserialize = null, ?stdClass $status = null)
    {
        $this->response = $response;
        $this->deserialize = $deserialize;
        if (is_null($status)) {
            $status = new MockStatus(Code::OK);
        }
        $this->status = $status;
    }

    /**
     * Immediately return the preset response object and status.
     * @return array The response object and status.
     */
    public function wait()
    {
        return [
            $this->deserializeMessage($this->response, $this->deserialize),
            $this->status,
        ];
    }
}
