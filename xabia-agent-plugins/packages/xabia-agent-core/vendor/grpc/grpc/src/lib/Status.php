<?php

namespace Grpc;

/**
 * This is an experimental and incomplete implementation of gRPC server
 * for PHP. APIs are _definitely_ going to be changed.
 *
 * DO NOT USE in production.
 */

/**
 * Class Status
 * @package Grpc
 */
class Status
{
    public static function status(int $code, string $details, ?array $metadata = null): array
    {
        $status = [
            'code' => $code,
            'details' => $details,
        ];
        if ($metadata) {
            $status['metadata'] = $metadata;
        }
        return $status;
    }

    public static function ok(?array $metadata = null): array
    {
        return Status::status(STATUS_OK, 'OK', $metadata);
    }
    public static function unimplemented(): array
    {
        return Status::status(STATUS_UNIMPLEMENTED, 'UNIMPLEMENTED');
    }
}
