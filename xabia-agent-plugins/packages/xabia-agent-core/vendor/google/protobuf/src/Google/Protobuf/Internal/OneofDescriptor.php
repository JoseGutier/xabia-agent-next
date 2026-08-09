<?php

namespace Google\Protobuf\Internal;

class OneofDescriptor
{
    use HasPublicDescriptorTrait;

    private $name;
    /** @var \Google\Protobuf\FieldDescriptor[] $fields */
    private $fields;

    public function __construct()
    {
        $this->public_desc = new \Google\Protobuf\OneofDescriptor($this);
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function getName()
    {
        return $this->name;
    }

    public function addField(FieldDescriptor $field)
    {
        $this->fields[] = $field;
    }

    public function getFields()
    {
        return $this->fields;
    }

    public function isSynthetic()
    {
        return !is_null($this->fields) && count($this->fields) === 1
            && $this->fields[0]->getProto3Optional();
    }

    public static function buildFromProto($oneof_proto, $desc, $index)
    {
        $oneof = new OneofDescriptor();
        $oneof->setName($oneof_proto->getName());
        foreach ($desc->getField() as $field) {
            /** @var FieldDescriptor $field */
            if ($field->getOneofIndex() == $index) {
                $oneof->addField($field);
                $field->setContainingOneof($oneof);
            }
        }
        return $oneof;
    }
}
