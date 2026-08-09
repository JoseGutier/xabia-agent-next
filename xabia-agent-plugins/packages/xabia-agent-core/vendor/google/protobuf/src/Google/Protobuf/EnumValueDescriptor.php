<?php

namespace Google\Protobuf;

class EnumValueDescriptor
{
    private $name;
    private $number;

    /**
     * @internal
     */
    public function __construct($name, $number)
    {
        $this->name = $name;
        $this->number = $number;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return int
     */
    public function getNumber()
    {
        return $this->number;
    }
}
