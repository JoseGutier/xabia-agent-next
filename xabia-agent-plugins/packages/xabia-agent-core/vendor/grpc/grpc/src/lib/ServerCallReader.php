<?php

namespace Grpc;

/**
 * This is an experimental and incomplete implementation of gRPC server
 * for PHP. APIs are _definitely_ going to be changed.
 *
 * DO NOT USE in production.
 */

class ServerCallReader
{
    public function __construct($call, string $request_type)
    {
        $this->call_ = $call;
        $this->request_type_ = $request_type;
    }

    public function read()
    {
        $event = $this->call_->startBatch([
            OP_RECV_MESSAGE => true,
        ]);
        if ($event->message === null) {
            return null;
        }
        $data = new $this->request_type_;
        $data->mergeFromString($event->message);
        return $data;
    }

    private $call_;
    private $request_type_;
}
