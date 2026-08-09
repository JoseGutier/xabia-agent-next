<?php

namespace Google\ApiCore\Testing;

use Google\ApiCore\ApiException;
use Google\ApiCore\ApiStatus;
use Google\ApiCore\ServerStreamingCallInterface;
use Google\Rpc\Code;
use stdClass;

/**
 * The MockServerStreamingCall class is used to mock out the \Grpc\ServerStreamingCall class
 * (https://github.com/grpc/grpc/blob/master/src/php/lib/Grpc/ServerStreamingCall.php)
 *
 * @internal
 */
class MockServerStreamingCall extends \Grpc\ServerStreamingCall implements ServerStreamingCallInterface
{
    use SerializationTrait;

    private $responses;
    private $status;

    /**
     * MockServerStreamingCall constructor.
     * @param mixed[] $responses A list of response objects.
     * @param callable|array|null $deserialize An optional deserialize method for the response object.
     * @param stdClass|null $status An optional status object. If set to null, a status of OK is used.
     */
    public function __construct(array $responses, $deserialize = null, ?stdClass $status = null)
    {
        $this->responses = $responses;
        $this->deserialize = $deserialize;
        if (is_null($status)) {
            $status = new MockStatus(Code::OK, 'OK', []);
        } elseif ($status instanceof stdClass) {
            if (!property_exists($status, 'metadata')) {
                $status->metadata = [];
            }
        }
        $this->status = $status;
    }

    public function responses()
    {
        while (count($this->responses) > 0) {
            $resp = array_shift($this->responses);
            $obj = $this->deserializeMessage($resp, $this->deserialize);
            yield $obj;
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
                Code::INTERNAL,
                ApiStatus::INTERNAL
            );
        }
        return $this->status;
    }
}
