<?php

namespace Google\ApiCore;

/**
 * Provides helper methods for gRPC support.
 *
 * @internal
 */
trait GrpcSupportTrait
{
    /**
     * @return bool
     */
    private static function getGrpcDependencyStatus()
    {
        return extension_loaded('grpc');
    }

    /**
     * @throws ValidationException
     */
    private static function validateGrpcSupport()
    {
        if (!self::getGrpcDependencyStatus()) {
            throw new ValidationException(
                'gRPC support has been requested but required dependencies ' .
                'have not been found. For details on how to install the ' .
                'gRPC extension please see https://cloud.google.com/php/grpc.'
            );
        }
    }
}
