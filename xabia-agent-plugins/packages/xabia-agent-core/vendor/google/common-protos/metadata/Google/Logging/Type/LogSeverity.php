<?php

namespace GPBMetadata\Google\Logging\Type;

class LogSeverity
{
    public static $is_initialized = false;

    public static function initOnce() {
        $pool = \Google\Protobuf\Internal\DescriptorPool::getGeneratedPool();

        if (static::$is_initialized == true) {
          return;
        }
        $pool->internalAddGeneratedFile(
            "\x0A\x92\x03\x0A&google/logging/type/log_severity.proto\x12\x13google.logging.type*\x82\x01\x0A\x0BLogSeverity\x12\x0B\x0A\x07DEFAULT\x10\x00\x12\x09\x0A\x05DEBUG\x10d\x12\x09\x0A\x04INFO\x10\xC8\x01\x12\x0B\x0A\x06NOTICE\x10\xAC\x02\x12\x0C\x0A\x07WARNING\x10\x90\x03\x12\x0A\x0A\x05ERROR\x10\xF4\x03\x12\x0D\x0A\x08CRITICAL\x10\xD8\x04\x12\x0A\x0A\x05ALERT\x10\xBC\x05\x12\x0E\x0A\x09EMERGENCY\x10\xA0\x06B\xC5\x01\x0A\x17com.google.logging.typeB\x10LogSeverityProtoP\x01Z8google.golang.org/genproto/googleapis/logging/type;ltype\xA2\x02\x04GLOG\xAA\x02\x19Google.Cloud.Logging.Type\xCA\x02\x19Google\\Cloud\\Logging\\Type\xEA\x02\x1CGoogle::Cloud::Logging::Typeb\x06proto3"
        , true);

        static::$is_initialized = true;
    }
}

