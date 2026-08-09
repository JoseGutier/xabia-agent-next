<?php

namespace Google\ApiCore\Testing;

use Google\Protobuf\Internal\Message;

/**
 * @internal
 */
trait SerializationTrait
{
    /**
     * @param mixed $message
     * @param mixed $deserialize
     */
    protected function deserializeMessage($message, $deserialize)
    {
        if ($message === null) {
            return null;
        }

        if ($deserialize === null) {
            return $message;
        }

        
        if (is_array($deserialize)) {
            list($className, $deserializeFunc) = $deserialize;
            /** @var Message $obj */
            $obj = new $className();
            if (method_exists($obj, $deserializeFunc)) {
                $obj->$deserializeFunc($message);
            } elseif (is_string($message)) {
                $obj->mergeFromString($message);
            }

            return $obj;
        }

        
        return call_user_func($deserialize, $message);
    }
}
