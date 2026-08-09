<?php

namespace Google\Protobuf;

use Google\Protobuf\Internal\GetPublicDescriptorTrait;

class OneofDescriptor
{
    use GetPublicDescriptorTrait;

    /** @var  \Google\Protobuf\Internal\OneofDescriptor $internal_desc */
    private $internal_desc;

    /**
     * @internal
     */
    public function __construct($internal_desc)
    {
        $this->internal_desc = $internal_desc;
    }

    /**
     * @return string The name of the oneof
     */
    public function getName()
    {
        return $this->internal_desc->getName();
    }

    /**
     * @param int $index Must be >= 0 and < getFieldCount()
     * @return FieldDescriptor
     */
    public function getField($index)
    {
        if (
            is_null($this->internal_desc->getFields())
            || !isset($this->internal_desc->getFields()[$index])
        ) {
            return null;
        }
        return $this->getPublicDescriptor($this->internal_desc->getFields()[$index]);
    }

    /**
     * @return int Number of fields in the oneof
     */
    public function getFieldCount()
    {
        return count($this->internal_desc->getFields());
    }

    public function isSynthetic()
    {
        return $this->internal_desc->isSynthetic();
    }
}
