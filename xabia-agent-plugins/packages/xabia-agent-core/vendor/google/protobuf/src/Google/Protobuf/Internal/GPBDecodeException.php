<?php

namespace Google\Protobuf\Internal;

class GPBDecodeException extends \Exception
{
    public function __construct(
        $message,
        $code = 0,
        ?\Exception $previous = null)
    {
        parent::__construct(
            "Error occurred during parsing: " . $message,
            $code,
            $previous);
    }
}
