<?php

namespace Grpc\Gcp;

/**
 * ChannelRef is used to record how many active streams the channel has.
 * This is a private class
 */
class ChannelRef
{
    
    private $opts;

    private $channel_id;
    private $affinity_ref;
    private $active_stream_ref;
    private $target;

    private $has_deserialized;
    private $real_channel;

    public function __construct($target, $channel_id, $opts, $affinity_ref=0, $active_stream_ref=0)
    {
        $this->target = $target;
        $this->channel_id = $channel_id;
        $this->affinity_ref = $affinity_ref;
        $this->active_stream_ref = $active_stream_ref;
        $this->opts = $opts;
        $this->has_deserialized = new CreatedByDeserializeCheck();
    }

    public function getRealChannel($credentials)
    {
        
        
        if (!$this->has_deserialized->getData()) {
            
            return $this->real_channel;
        }
        
        
        
        
        
        

        
        if (!array_key_exists('credentials', $this->opts)) {
            $this->opts['credentials'] = $credentials;
        }
        $real_channel = new \Grpc\Channel($this->target, $this->opts);
        $this->real_channel = $real_channel;
        
        $this->has_deserialized->setData(0);
        return $real_channel;
    }

    public function getAffinityRef()
    {
        return $this->affinity_ref;
    }
    public function getActiveStreamRef()
    {
        return $this->active_stream_ref;
    }
    public function affinityRefIncr()
    {
        $this->affinity_ref += 1;
    }
    public function affinityRefDecr()
    {
        $this->affinity_ref -= 1;
    }
    public function activeStreamRefIncr()
    {
        $this->active_stream_ref += 1;
    }
    public function activeStreamRefDecr()
    {
        $this->active_stream_ref -= 1;
    }
}
