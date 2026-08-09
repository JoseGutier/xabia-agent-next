<?php

namespace Google\Protobuf\Internal;

class RawInputStream
{

    private $buffer;

    public function __construct($buffer)
    {
        $this->buffer = $buffer;
    }

    public function getData()
    {
        return $this->buffer;
    }

}
