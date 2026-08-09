<?php

namespace Google\ApiCore;

/**
 * Container class for Protobuf label constants. See FieldDescriptorProto.Label in
 * https://github.com/google/protobuf/blob/master/src/google/protobuf/descriptor.proto
 */
class GPBLabel
{
    const OPTIONAL = 1;
    const REQUIRED = 2;
    const REPEATED = 3;
}
