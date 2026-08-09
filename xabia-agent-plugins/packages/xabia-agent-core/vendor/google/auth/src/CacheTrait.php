<?php

namespace Google\Auth;

use Psr\Cache\CacheItemPoolInterface;

trait CacheTrait
{
    /**
     * @var int
     */
    private $maxKeyLength = 64;

    /**
     * @var array<mixed>
     */
    private $cacheConfig;

    /**
     * @var ?CacheItemPoolInterface
     */
    private $cache;

    /**
     * Gets the cached value if it is present in the cache when that is
     * available.
     *
     * @param mixed $k
     *
     * @return mixed
     */
    private function getCachedValue($k)
    {
        if (is_null($this->cache)) {
            return null;
        }

        $key = $this->getFullCacheKey($k);
        if (is_null($key)) {
            return null;
        }

        $cacheItem = $this->cache->getItem($key);
        if ($cacheItem->isHit()) {
            return $cacheItem->get();
        }
    }

    /**
     * Saves the value in the cache when that is available.
     *
     * @param mixed $k
     * @param mixed $v
     * @return mixed
     */
    private function setCachedValue($k, $v)
    {
        if (is_null($this->cache)) {
            return null;
        }

        $key = $this->getFullCacheKey($k);
        if (is_null($key)) {
            return null;
        }

        $cacheItem = $this->cache->getItem($key);
        $cacheItem->set($v);
        $cacheItem->expiresAfter($this->cacheConfig['lifetime']);
        return $this->cache->save($cacheItem);
    }

    /**
     * @param null|string $key
     * @return null|string
     */
    private function getFullCacheKey($key)
    {
        if (is_null($key)) {
            return null;
        }

        $key = ($this->cacheConfig['prefix'] ?? '') . $key;

        
        $key = preg_replace('|[^a-zA-Z0-9_\.!]|', '', $key);

        
        if ($this->maxKeyLength && strlen($key) > $this->maxKeyLength) {
            $key = substr(hash('sha256', $key), 0, $this->maxKeyLength);
        }

        return $key;
    }
}
