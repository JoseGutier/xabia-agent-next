<?php

namespace Grpc\Gcp;

/**
 * GCPCallInvoker updates the channel pool(GcpExtensionChannel) for
 * each RPC. The idea is:
 * Before the RPC starts, pick a channel from the channel pool:
 *   - if the RPC is bound to a channel, use that channel.
 *   - if the RPC doesn't bound to a channel, use the one with minimum active streams.
 * After the RPC finishes, update the active stream ref count.
 *   - if the RPC is defined as bind, bind the channel with corresponding key like
 *     spanner session name.
 *   - if the RPC is defined as unbind, unbind the channel with the key.
 */
class GCPCallInvoker implements \Grpc\CallInvoker
{
    private $channel;
    private $affinity_conf;

    /**
     * @param array  $affinity_conf Store the affinity config for process each RPC.
     */
    public function __construct($affinity_conf)
    {
        $this->affinity_conf = $affinity_conf;
    }

    /**
     * @param string  $hostname
     * @param array  $opts
     * @return GcpExtensionChannel
     */
    public function createChannelFactory($hostname, $opts)
    {
        if ($this->channel) {
            
            
            $this->channel->updateOpts($opts);
        } else {
            $opts['affinity_conf'] = $this->affinity_conf;
            $channel = new GcpExtensionChannel($hostname, $opts);
            $this->channel = $channel;
        }
        return $this->channel;
    }

    
    public function GetChannel()
    {
        return $this->channel;
    }

    public function UnaryCall($channel, $method, $deserialize, $options)
    {
        return new GCPUnaryCall($channel, $method, $deserialize, $options);
    }
    public function ClientStreamingCall($channel, $method, $deserialize, $options)
    {
        return new GCPClientStreamCall($channel, $method, $deserialize, $options);
    }
    public function ServerStreamingCall($channel, $method, $deserialize, $options)
    {
        return new GCPServerStreamCall($channel, $method, $deserialize, $options);
    }
    public function BidiStreamingCall($channel, $method, $deserialize, $options)
    {
        return new GCPBidiStreamingCall($channel, $method, $deserialize, $options);
    }
}
