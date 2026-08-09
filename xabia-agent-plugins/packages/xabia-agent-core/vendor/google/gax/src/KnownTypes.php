<?php

namespace Google\ApiCore;

/**
 * @internal
 */
class KnownTypes
{
    private static bool $initialized = false;

    /** @deprecated use BIN_TYPES instead */
    public const GRPC_TYPES = self::BIN_TYPES;
    /** @deprecated use TYPE_URLS instead */
    public const JSON_TYPES = self::TYPE_URLS;

    public const BIN_TYPES = [
        'google.rpc.retryinfo-bin' => \Google\Rpc\RetryInfo::class,
        'google.rpc.debuginfo-bin' => \Google\Rpc\DebugInfo::class,
        'google.rpc.quotafailure-bin' => \Google\Rpc\QuotaFailure::class,
        'google.rpc.badrequest-bin' => \Google\Rpc\BadRequest::class,
        'google.rpc.requestinfo-bin' => \Google\Rpc\RequestInfo::class,
        'google.rpc.resourceinfo-bin' => \Google\Rpc\ResourceInfo::class,
        'google.rpc.errorinfo-bin' => \Google\Rpc\ErrorInfo::class,
        'google.rpc.help-bin' => \Google\Rpc\Help::class,
        'google.rpc.localizedmessage-bin' => \Google\Rpc\LocalizedMessage::class,
        'google.rpc.preconditionfailure-bin' => \Google\Rpc\PreconditionFailure::class,
    ];

    public const TYPE_URLS = [
        'type.googleapis.com/google.rpc.RetryInfo' => \Google\Rpc\RetryInfo::class,
        'type.googleapis.com/google.rpc.DebugInfo' => \Google\Rpc\DebugInfo::class,
        'type.googleapis.com/google.rpc.QuotaFailure' => \Google\Rpc\QuotaFailure::class,
        'type.googleapis.com/google.rpc.BadRequest' => \Google\Rpc\BadRequest::class,
        'type.googleapis.com/google.rpc.RequestInfo' => \Google\Rpc\RequestInfo::class,
        'type.googleapis.com/google.rpc.ResourceInfo' => \Google\Rpc\ResourceInfo::class,
        'type.googleapis.com/google.rpc.ErrorInfo' => \Google\Rpc\ErrorInfo::class,
        'type.googleapis.com/google.rpc.Help' => \Google\Rpc\Help::class,
        'type.googleapis.com/google.rpc.LocalizedMessage' => \Google\Rpc\LocalizedMessage::class,
        'type.googleapis.com/google.rpc.PreconditionFailure' => \Google\Rpc\PreconditionFailure::class,
    ];

    public static function allKnownTypes(): array
    {
        return array_values(self::TYPE_URLS);
    }

    public static function addKnownTypesToDescriptorPool()
    {
        if (self::$initialized) {
            return;
        }

        
        \GPBMetadata\Google\Rpc\ErrorDetails::initOnce();
        self::$initialized = true;
    }
}
