<?php

namespace Google\ApiCore\Transport\Grpc;

/**
 * Temporary class to support an interceptor-like interface until gRPC interceptor support is
 * available.
 *
 * @experimental
 * @deprecated Deprecated in favor of implementations extending {@see \Grpc\Interceptor}.
 */
interface UnaryInterceptorInterface
{
    /**
     * @param string $method
     * @param \Google\Protobuf\Internal\Message $argument
     * @param callable $deserialize
     * @param array $metadata
     * @param array $options
     * @param callable $continuation
     * @return mixed
     */
    public function interceptUnaryUnary(
        $method,
        $argument,
        $deserialize,
        array $metadata,
        array $options,
        callable $continuation
    );
}
