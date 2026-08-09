<?php

namespace Google\Protobuf;

class DescriptorPool
{
    private static $pool;

    private $internal_pool;

    /**
     * @return DescriptorPool
     */
    public static function getGeneratedPool()
    {
        if (!isset(self::$pool)) {
            self::$pool = new DescriptorPool(\Google\Protobuf\Internal\DescriptorPool::getGeneratedPool());
        }
        return self::$pool;
    }

    private function __construct($internal_pool)
    {
        $this->internal_pool = $internal_pool;
    }

    /**
     * @param string $className A fully qualified protobuf class name
     * @return Descriptor
     */
    public function getDescriptorByClassName($className)
    {
        $desc = $this->internal_pool->getDescriptorByClassName($className);
        return is_null($desc) ? null : $desc->getPublicDescriptor();
    }

    /**
     * @param string $className A fully qualified protobuf class name
     * @return EnumDescriptor
     */
    public function getEnumDescriptorByClassName($className)
    {
        $desc = $this->internal_pool->getEnumDescriptorByClassName($className);
        return is_null($desc) ? null : $desc->getPublicDescriptor();
    }
}
