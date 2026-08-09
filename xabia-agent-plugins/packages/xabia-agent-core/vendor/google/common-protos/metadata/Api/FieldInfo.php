<?php

namespace GPBMetadata\Google\Api;

class FieldInfo
{
    public static $is_initialized = false;

    public static function initOnce() {
        $pool = \Google\Protobuf\Internal\DescriptorPool::getGeneratedPool();

        if (static::$is_initialized == true) {
          return;
        }
        $pool->internalAddGeneratedFile(
            "\x0A\xA9\x03\x0A\x1Bgoogle/api/field_info.proto\x12\x0Agoogle.api\x1A google/protobuf/descriptor.proto\"\xC1\x01\x0A\x09FieldInfo\x12,\x0A\x06format\x18\x01 \x01(\x0E2\x1C.google.api.FieldInfo.Format\x123\x0A\x10referenced_types\x18\x02 \x03(\x0B2\x19.google.api.TypeReference\"Q\x0A\x06Format\x12\x16\x0A\x12FORMAT_UNSPECIFIED\x10\x00\x12\x09\x0A\x05UUID4\x10\x01\x12\x08\x0A\x04IPV4\x10\x02\x12\x08\x0A\x04IPV6\x10\x03\x12\x10\x0A\x0CIPV4_OR_IPV6\x10\x04\"\"\x0A\x0DTypeReference\x12\x11\x0A\x09type_name\x18\x01 \x01(\x09Bl\x0A\x0Ecom.google.apiB\x0EFieldInfoProtoP\x01ZAgoogle.golang.org/genproto/googleapis/api/annotations;annotations\xA2\x02\x04GAPIb\x06proto3"
        , true);

        static::$is_initialized = true;
    }
}

