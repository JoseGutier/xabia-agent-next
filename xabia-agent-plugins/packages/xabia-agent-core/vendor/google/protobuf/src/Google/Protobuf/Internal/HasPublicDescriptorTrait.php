<?php

namespace Google\Protobuf\Internal;

trait HasPublicDescriptorTrait
{
    private $public_desc;

    public function getPublicDescriptor()
    {
        return $this->public_desc;
    }
}
