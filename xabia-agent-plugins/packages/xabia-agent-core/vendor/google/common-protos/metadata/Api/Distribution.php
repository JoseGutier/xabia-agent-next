<?php

namespace GPBMetadata\Google\Api;

class Distribution
{
    public static $is_initialized = false;

    public static function initOnce() {
        $pool = \Google\Protobuf\Internal\DescriptorPool::getGeneratedPool();

        if (static::$is_initialized == true) {
          return;
        }
        \GPBMetadata\Google\Protobuf\Any::initOnce();
        \GPBMetadata\Google\Protobuf\Timestamp::initOnce();
        $pool->internalAddGeneratedFile(
            "\x0A\xA3\x08\x0A\x1Dgoogle/api/distribution.proto\x12\x0Agoogle.api\x1A\x1Fgoogle/protobuf/timestamp.proto\"\xD9\x06\x0A\x0CDistribution\x12\x0D\x0A\x05count\x18\x01 \x01(\x03\x12\x0C\x0A\x04mean\x18\x02 \x01(\x01\x12 \x0A\x18sum_of_squared_deviation\x18\x03 \x01(\x01\x12-\x0A\x05range\x18\x04 \x01(\x0B2\x1E.google.api.Distribution.Range\x12>\x0A\x0Ebucket_options\x18\x06 \x01(\x0B2&.google.api.Distribution.BucketOptions\x12\x15\x0A\x0Dbucket_counts\x18\x07 \x03(\x03\x124\x0A\x09exemplars\x18\x0A \x03(\x0B2!.google.api.Distribution.Exemplar\x1A!\x0A\x05Range\x12\x0B\x0A\x03min\x18\x01 \x01(\x01\x12\x0B\x0A\x03max\x18\x02 \x01(\x01\x1A\xB5\x03\x0A\x0DBucketOptions\x12G\x0A\x0Elinear_buckets\x18\x01 \x01(\x0B2-.google.api.Distribution.BucketOptions.LinearH\x00\x12Q\x0A\x13exponential_buckets\x18\x02 \x01(\x0B22.google.api.Distribution.BucketOptions.ExponentialH\x00\x12K\x0A\x10explicit_buckets\x18\x03 \x01(\x0B2/.google.api.Distribution.BucketOptions.ExplicitH\x00\x1AC\x0A\x06Linear\x12\x1A\x0A\x12num_finite_buckets\x18\x01 \x01(\x05\x12\x0D\x0A\x05width\x18\x02 \x01(\x01\x12\x0E\x0A\x06offset\x18\x03 \x01(\x01\x1AO\x0A\x0BExponential\x12\x1A\x0A\x12num_finite_buckets\x18\x01 \x01(\x05\x12\x15\x0A\x0Dgrowth_factor\x18\x02 \x01(\x01\x12\x0D\x0A\x05scale\x18\x03 \x01(\x01\x1A\x1A\x0A\x08Explicit\x12\x0E\x0A\x06bounds\x18\x01 \x03(\x01B\x09\x0A\x07options\x1As\x0A\x08Exemplar\x12\x0D\x0A\x05value\x18\x01 \x01(\x01\x12-\x0A\x09timestamp\x18\x02 \x01(\x0B2\x1A.google.protobuf.Timestamp\x12)\x0A\x0Battachments\x18\x03 \x03(\x0B2\x14.google.protobuf.AnyBq\x0A\x0Ecom.google.apiB\x11DistributionProtoP\x01ZCgoogle.golang.org/genproto/googleapis/api/distribution;distribution\xA2\x02\x04GAPIb\x06proto3"
        , true);

        static::$is_initialized = true;
    }
}

