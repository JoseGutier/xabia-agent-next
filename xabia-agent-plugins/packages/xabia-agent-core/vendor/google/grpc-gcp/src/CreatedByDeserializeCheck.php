<?php

namespace Grpc\Gcp;

/**
 * DeserializeCheck is used to check whether _ChannelRef is created by deserialization or not.
 * If it is, $real_channel is invalid thus we need to recreate it using $opts.
 * If not, we can use $real_channel directly instead of creating a new one.
 * It is useful to handle 'force_new' channel option.
 * This is a private class
 */
class CreatedByDeserializeCheck implements \Serializable
{
    
    private $data;
    public function __construct()
    {
        $this->data = 1;
    }

    /**
     * @return string
     */
    public function serialize()
    {
        return '0';
    }

    /**
     * @return string
     */
    public function __serialize()
    {
        return $this->serialize();
    }

    /**
     * @param string $data
     */
    public function unserialize($data)
    {
        $this->data = 1;
    }

    /**
     * @param string $data
     */
    public function __unserialize($data)
    {
       $this->unserialize($data);
    }

    /**
     * @param $data
     */
    public function setData($data)
    {
        $this->data = $data;
    }

    /**
     * @return int
     */
    public function getData()
    {
        return $this->data;
    }
}
