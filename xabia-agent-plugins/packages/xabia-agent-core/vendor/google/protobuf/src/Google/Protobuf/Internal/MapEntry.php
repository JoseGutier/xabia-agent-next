<?php

namespace Google\Protobuf\Internal;

use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\Message;

class MapEntry extends Message
{
    public $key;
    public $value;

    public function __construct($desc) {
        parent::__construct($desc);
        
        
        
        $value_field = $desc->getFieldByNumber(2);
        if ($value_field->getType() == GPBType::MESSAGE) {
            $klass = $value_field->getMessageType()->getClass();
            $value = new $klass;
            $this->setValue($value);
        }
    }

    public function setKey($key) {
      $this->key = $key;
    }

    public function getKey() {
      return $this->key;
    }

    public function setValue($value) {
      $this->value = $value;
    }

    public function getValue() {
      return $this->value;
    }
}
