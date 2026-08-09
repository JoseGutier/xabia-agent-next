<?php

namespace Grpc;

/**
 * Default call invoker in the gRPC stub.
 */
class DefaultCallInvoker implements CallInvoker
{
    public function createChannelFactory($hostname, $opts) {
        return new Channel($hostname, $opts);
    }

    public function UnaryCall($channel, $method, $deserialize, $options) {
        return new UnaryCall($channel, $method, $deserialize, $options);
    }

    public function ClientStreamingCall($channel, $method, $deserialize, $options) {
        return new ClientStreamingCall($channel, $method, $deserialize, $options);
    }

    public function ServerStreamingCall($channel, $method, $deserialize, $options) {
        return new ServerStreamingCall($channel, $method, $deserialize, $options);
    }

    public function BidiStreamingCall($channel, $method, $deserialize, $options) {
        return new BidiStreamingCall($channel, $method, $deserialize, $options);
    }
}

