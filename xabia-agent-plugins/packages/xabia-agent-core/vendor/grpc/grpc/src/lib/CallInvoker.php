<?php

namespace Grpc;

/**
 * CallInvoker is used to pass the self defined channel into the stub,
 * while intercept each RPC with the channel accessible.
 */
interface CallInvoker
{
    public function createChannelFactory($hostname, $opts);
    public function UnaryCall($channel, $method, $deserialize, $options);
    public function ClientStreamingCall($channel, $method, $deserialize, $options);
    public function ServerStreamingCall($channel, $method, $deserialize, $options);
    public function BidiStreamingCall($channel, $method, $deserialize, $options);
}
