<?php

namespace Google\Protobuf\Internal;

class GPBWireType
{
    const VARINT           = 0;
    const FIXED64          = 1;
    const LENGTH_DELIMITED = 2;
    const START_GROUP      = 3;
    const END_GROUP        = 4;
    const FIXED32          = 5;
}
