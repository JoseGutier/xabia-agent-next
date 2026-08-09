<?php

namespace Grpc;

/**
 * This is an experimental and incomplete implementation of gRPC server
 * for PHP. APIs are _definitely_ going to be changed.
 *
 * DO NOT USE in production.
 */

class ServerContext
{
    public function __construct($event)
    {
        $this->event = $event;
    }

    public function clientMetadata()
    {
        return $this->event->metadata;
    }
    public function deadline()
    {
        return $this->event->absolute_deadline;
    }
    public function host()
    {
        return $this->event->host;
    }
    public function method()
    {
        return $this->event->method;
    }

    public function setInitialMetadata($initialMetadata)
    {
        $this->initialMetadata_ = $initialMetadata;
    }

    public function initialMetadata()
    {
        return $this->initialMetadata_;
    }

    public function setStatus($status)
    {
        $this->status_ = $status;
    }

    public function status()
    {
        return $this->status_;
    }

    private $event;
    private $initialMetadata_;
    private $status_;
}
