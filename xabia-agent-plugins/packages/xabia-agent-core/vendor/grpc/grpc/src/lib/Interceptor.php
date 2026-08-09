<?php

namespace Grpc;

/**
 * Represents an interceptor that intercept RPC invocations before call starts.
 * There is one proposal related to the argument $deserialize under the review.
 * The proposal link is https://github.com/grpc/proposal/pull/86.
 */
class Interceptor
{
    public function interceptUnaryUnary(
        $method,
        $argument,
        $deserialize,
        $continuation,
        array $metadata = [],
        array $options = []
    ) {
        return $continuation($method, $argument, $deserialize, $metadata, $options);
    }

    public function interceptStreamUnary(
        $method,
        $deserialize,
        $continuation,
        array $metadata = [],
        array $options = []
    ) {
        return $continuation($method, $deserialize, $metadata, $options);
    }

    public function interceptUnaryStream(
        $method,
        $argument,
        $deserialize,
        $continuation,
        array $metadata = [],
        array $options = []
    ) {
        return $continuation($method, $argument, $deserialize, $metadata, $options);
    }

    public function interceptStreamStream(
        $method,
        $deserialize,
        $continuation,
        array $metadata = [],
        array $options = []
    ) {
        return $continuation($method, $deserialize, $metadata, $options);
    }

    /**
     * Intercept the methods with Channel
     *
     * @param Channel|InterceptorChannel $channel An already created Channel or InterceptorChannel object (optional)
     * @param Interceptor|Interceptor[] $interceptors interceptors to be added
     *
     * @return InterceptorChannel
     */
    public static function intercept($channel, $interceptors)
    {
        if (is_array($interceptors)) {
            for ($i = count($interceptors) - 1; $i >= 0; $i--) {
                $channel = new Internal\InterceptorChannel($channel, $interceptors[$i]);
            }
        } else {
            $channel =  new Internal\InterceptorChannel($channel, $interceptors);
        }
        return $channel;
    }
}

