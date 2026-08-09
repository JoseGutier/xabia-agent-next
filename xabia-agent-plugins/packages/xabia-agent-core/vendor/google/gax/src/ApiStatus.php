<?php

namespace Google\ApiCore;

use Google\Rpc\Code;

class ApiStatus
{
    const OK = 'OK';
    const CANCELLED = 'CANCELLED';
    const UNKNOWN = 'UNKNOWN';
    const INVALID_ARGUMENT = 'INVALID_ARGUMENT';
    const DEADLINE_EXCEEDED = 'DEADLINE_EXCEEDED';
    const NOT_FOUND = 'NOT_FOUND';
    const ALREADY_EXISTS = 'ALREADY_EXISTS';
    const PERMISSION_DENIED = 'PERMISSION_DENIED';
    const RESOURCE_EXHAUSTED = 'RESOURCE_EXHAUSTED';
    const FAILED_PRECONDITION = 'FAILED_PRECONDITION';
    const ABORTED = 'ABORTED';
    const OUT_OF_RANGE = 'OUT_OF_RANGE';
    const UNIMPLEMENTED = 'UNIMPLEMENTED';
    const INTERNAL = 'INTERNAL';
    const UNAVAILABLE = 'UNAVAILABLE';
    const DATA_LOSS = 'DATA_LOSS';
    const UNAUTHENTICATED = 'UNAUTHENTICATED';

    const UNRECOGNIZED_STATUS = 'UNRECOGNIZED_STATUS';
    const UNRECOGNIZED_CODE = -1;

    private static $apiStatusToCodeMap = [
        ApiStatus::OK => Code::OK,
        ApiStatus::CANCELLED => Code::CANCELLED,
        ApiStatus::UNKNOWN => Code::UNKNOWN,
        ApiStatus::INVALID_ARGUMENT => Code::INVALID_ARGUMENT,
        ApiStatus::DEADLINE_EXCEEDED => Code::DEADLINE_EXCEEDED,
        ApiStatus::NOT_FOUND => Code::NOT_FOUND,
        ApiStatus::ALREADY_EXISTS => Code::ALREADY_EXISTS,
        ApiStatus::PERMISSION_DENIED => Code::PERMISSION_DENIED,
        ApiStatus::RESOURCE_EXHAUSTED => Code::RESOURCE_EXHAUSTED,
        ApiStatus::FAILED_PRECONDITION => Code::FAILED_PRECONDITION,
        ApiStatus::ABORTED => Code::ABORTED,
        ApiStatus::OUT_OF_RANGE => Code::OUT_OF_RANGE,
        ApiStatus::UNIMPLEMENTED => Code::UNIMPLEMENTED,
        ApiStatus::INTERNAL => Code::INTERNAL,
        ApiStatus::UNAVAILABLE => Code::UNAVAILABLE,
        ApiStatus::DATA_LOSS => Code::DATA_LOSS,
        ApiStatus::UNAUTHENTICATED => Code::UNAUTHENTICATED,
    ];
    private static $codeToApiStatusMap = [
        Code::OK => ApiStatus::OK,
        Code::CANCELLED => ApiStatus::CANCELLED,
        Code::UNKNOWN => ApiStatus::UNKNOWN,
        Code::INVALID_ARGUMENT => ApiStatus::INVALID_ARGUMENT,
        Code::DEADLINE_EXCEEDED => ApiStatus::DEADLINE_EXCEEDED,
        Code::NOT_FOUND => ApiStatus::NOT_FOUND,
        Code::ALREADY_EXISTS => ApiStatus::ALREADY_EXISTS,
        Code::PERMISSION_DENIED => ApiStatus::PERMISSION_DENIED,
        Code::RESOURCE_EXHAUSTED => ApiStatus::RESOURCE_EXHAUSTED,
        Code::FAILED_PRECONDITION => ApiStatus::FAILED_PRECONDITION,
        Code::ABORTED => ApiStatus::ABORTED,
        Code::OUT_OF_RANGE => ApiStatus::OUT_OF_RANGE,
        Code::UNIMPLEMENTED => ApiStatus::UNIMPLEMENTED,
        Code::INTERNAL => ApiStatus::INTERNAL,
        Code::UNAVAILABLE => ApiStatus::UNAVAILABLE,
        Code::DATA_LOSS => ApiStatus::DATA_LOSS,
        Code::UNAUTHENTICATED => ApiStatus::UNAUTHENTICATED,
    ];
    private static $httpStatusCodeToRpcCodeMap = [
        400 => Code::INVALID_ARGUMENT,
        401 => Code::UNAUTHENTICATED,
        403 => Code::PERMISSION_DENIED,
        404 => Code::NOT_FOUND,
        409 => Code::ABORTED,
        416 => Code::OUT_OF_RANGE,
        429 => Code::RESOURCE_EXHAUSTED,
        499 => Code::CANCELLED,
        501 => Code::UNIMPLEMENTED,
        503 => Code::UNAVAILABLE,
        504 => Code::DEADLINE_EXCEEDED,
    ];

    /**
     * @param string $status
     * @return bool
     */
    public static function isValidStatus(string $status)
    {
        return array_key_exists($status, self::$apiStatusToCodeMap);
    }

    /**
     * @param int $code
     * @return string
     */
    public static function statusFromRpcCode(int $code)
    {
        if (array_key_exists($code, self::$codeToApiStatusMap)) {
            return self::$codeToApiStatusMap[$code];
        }
        return ApiStatus::UNRECOGNIZED_STATUS;
    }

    /**
     * @param string $status
     * @return int
     */
    public static function rpcCodeFromStatus(string $status)
    {
        if (array_key_exists($status, self::$apiStatusToCodeMap)) {
            return self::$apiStatusToCodeMap[$status];
        }
        return ApiStatus::UNRECOGNIZED_CODE;
    }

    /**
     * Maps HTTP status codes to Google\Rpc\Code codes.
     * Some codes are left out because they map to multiple gRPC codes (e.g. 500).
     *
     * @param int $httpStatusCode
     * @return int
     */
    public static function rpcCodeFromHttpStatusCode(int $httpStatusCode)
    {
        if (array_key_exists($httpStatusCode, self::$httpStatusCodeToRpcCodeMap)) {
            return self::$httpStatusCodeToRpcCodeMap[$httpStatusCode];
        }
        
        if ($httpStatusCode >= 200 && $httpStatusCode < 300) {
            return Code::OK;
        }
        
        if ($httpStatusCode >= 400 && $httpStatusCode < 500) {
            return Code::FAILED_PRECONDITION;
        }
        
        if ($httpStatusCode >= 500 && $httpStatusCode < 600) {
            return Code::INTERNAL;
        }
        
        return ApiStatus::UNRECOGNIZED_CODE;
    }
}
