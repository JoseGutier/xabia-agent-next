<?php

namespace Google\Protobuf;

class EnumDescriptor
{
    private $internal_desc;

    /**
     * @internal
     */
    public function __construct($internal_desc)
    {
        $this->internal_desc = $internal_desc;
    }

    /**
     * @return string Full protobuf message name
     */
    public function getFullName()
    {
        return $this->internal_desc->getFullName();
    }

    /**
     * @return string PHP class name
     */
    public function getClass()
    {
        return $this->internal_desc->getClass();
    }

    /**
     * @param int $index Must be >= 0 and < getValueCount()
     * @return EnumValueDescriptor
     */
    public function getValue($index)
    {
        return $this->internal_desc->getValueDescriptorByIndex($index);
    }

    /**
     * @return int Number of values in enum
     */
    public function getValueCount()
    {
        return $this->internal_desc->getValueCount();
    }
}
