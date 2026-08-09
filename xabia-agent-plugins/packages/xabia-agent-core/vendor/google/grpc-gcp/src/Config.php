<?php

namespace Grpc\Gcp;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Config is used to enable the support for the channel management.
 */
class Config
{
    private $hostname;
    private $gcp_call_invoker;
    private $cross_script_shmem_enabled;
    private $supported_sapis = ['fpm-fcgi', 'cli-server'];

    /**
     * @param string  $target The target API we want to manage the connection.
     * @param \Grpc\Gcp\ApiConfig   $conf
     * @param CacheItemPoolInterface $cacheItemPool A pool for storing configuration and channels
     *                                            cross requests within a single worker process.
     * @throws \RuntimeException When a failure occurs while attempting to attach to shared memory.
     */
    public function __construct($target, $conf = null, ?CacheItemPoolInterface $cacheItemPool = null)
    {
        if ($conf == null) {
            
            $this->gcp_call_invoker = new \Grpc\DefaultCallInvoker();
            return;
        }

        $gcp_channel = null;
        $url_host = parse_url($target, PHP_URL_HOST);
        $this->hostname = $url_host ? $url_host : $target;
        $channel_pool_key = $this->hostname . '.gcp.channel.' . getmypid();

        if (!$cacheItemPool) {
            $affinity_conf = $this->parseConfObject($conf);
            $gcp_call_invoker = new GCPCallInvoker($affinity_conf);
            $this->gcp_call_invoker = $gcp_call_invoker;
        } else {
            $item = $cacheItemPool->getItem($channel_pool_key);
            if ($item->isHit()) {
                
                $gcp_call_invoker = unserialize($item->get());
            } else {
                $affinity_conf = $this->parseConfObject($conf);
                
                $gcp_call_invoker = new GCPCallInvoker($affinity_conf);
            }
            $this->gcp_call_invoker = $gcp_call_invoker;
            register_shutdown_function(function ($gcp_call_invoker, $cacheItemPool, $item) {
                
                $item->set(serialize($gcp_call_invoker));
                $cacheItemPool->save($item);
            }, $gcp_call_invoker, $cacheItemPool, $item);
        }
    }

    /**
     * @return \Grpc\CallInvoker The call invoker to be hooked into the gRPC
     */
    public function callInvoker()
    {
        return $this->gcp_call_invoker;
    }

    /**
     * @return string The URI of the endpoint
     */
    public function getTarget()
    {
        return $this->channel->getTarget();
    }

    private function parseConfObject($conf_object)
    {
        $config = json_decode($conf_object->serializeToJsonString(), true);
        if (isset($config['channelPool'])) {
            $affinity_conf['channelPool'] = $config['channelPool'];
        }
        $aff_by_method = array();
        if (isset($config['method'])) {
            for ($i = 0; $i < count($config['method']); $i++) {
                
                
                if (!array_key_exists('command', $config['method'][$i]['affinity'])) {
                    $config['method'][$i]['affinity']['command'] = 'BOUND';
                }
                $aff_by_method[$config['method'][$i]['name'][0]] = $config['method'][$i]['affinity'];
            }
        }
        $affinity_conf['affinity_by_method'] = $aff_by_method;
        return $affinity_conf;
    }

}
