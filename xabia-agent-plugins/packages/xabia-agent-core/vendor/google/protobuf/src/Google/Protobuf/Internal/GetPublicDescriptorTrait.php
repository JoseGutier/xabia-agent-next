<?php

namespace Google\Protobuf\Internal;

trait GetPublicDescriptorTrait
{
    private function getPublicDescriptor($desc)
    {
        return is_null($desc) ? null : $desc->getPublicDescriptor();
    }
}
