<?php

namespace Google\ApiCore\Testing;

/**
 * Class ReceivedRequest used to hold the function name and request object of a call
 * make to a mock gRPC stub.
 *
 * @internal
 */
class ReceivedRequest
{
    private $actualCall;

    public function __construct($funcCall, $requestObject, $deserialize = null, $metadata = [], $options = [])
    {
        $this->actualCall = [
            'funcCall' => $funcCall,
            'request' => $requestObject,
            'deserialize' => $deserialize,
            'metadata' => $metadata,
            'options' => $options,
        ];
    }

    public function getArray()
    {
        return $this->actualCall;
    }

    public function getFuncCall()
    {
        return $this->actualCall['funcCall'];
    }

    public function getRequestObject()
    {
        return $this->actualCall['request'];
    }

    public function getMetadata()
    {
        return $this->actualCall['metadata'];
    }

    public function getOptions()
    {
        return $this->actualCall['options'];
    }
}
