<?php

namespace Grpc;

/**
 * This is an experimental and incomplete implementation of gRPC server
 * for PHP. APIs are _definitely_ going to be changed.
 *
 * DO NOT USE in production.
 */

class MethodDescriptor
{
    public function __construct(
        object $service,
        string $method_name,
        string $request_type,
        int $call_type
    ) {
        $this->service = $service;
        $this->method_name = $method_name;
        $this->request_type = $request_type;
        $this->call_type = $call_type;
    }

    public const UNARY_CALL = 0;
    public const SERVER_STREAMING_CALL = 1;
    public const CLIENT_STREAMING_CALL = 2;
    public const BIDI_STREAMING_CALL = 3;

    public $service;
    public $method_name;
    public $request_type;
    public $call_type;
}
