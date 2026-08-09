<?php

namespace Google\Protobuf\Internal;

class OneofField
{

    private $desc;
    private $field_name;
    private $number = 0;
    private $value;

    public function __construct($desc)
    {
        $this->desc = $desc;
    }

    public function setValue($value)
    {
        $this->value = $value;
    }

    public function getValue()
    {
        return $this->value;
    }

    public function setFieldName($field_name)
    {
        $this->field_name = $field_name;
    }

    public function getFieldName()
    {
        return $this->field_name;
    }

    public function setNumber($number)
    {
        $this->number = $number;
    }

    public function getNumber()
    {
        return $this->number;
    }
}
