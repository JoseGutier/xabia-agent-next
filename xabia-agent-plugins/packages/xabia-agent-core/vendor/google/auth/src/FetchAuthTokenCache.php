<?php

namespace Google\Auth;

use Psr\Cache\CacheItemPoolInterface;

/**
 * A class to implement caching for any object implementing
 * FetchAuthTokenInterface
 */
class FetchAuthTokenCache implements
    FetchAuthTokenInterface,
    GetQuotaProjectInterface,
    GetUniverseDomainInterface,
    SignBlobInterface,
    ProjectIdProviderInterface,
    UpdateMetadataInterface
{
    use CacheTrait;

    /**
     * @var FetchAuthTokenInterface
     */
    private $fetcher;

    /**
     * @var int
     */
    private $eagerRefreshThresholdSeconds = 10;

    /**
     * @param FetchAuthTokenInterface $fetcher A credentials fetcher
     * @param array<mixed>|null $cacheConfig Configuration for the cache
     * @param CacheItemPoolInterface $cache
     */
    public function __construct(
        FetchAuthTokenInterface $fetcher,
        ?array $cacheConfig = null,
        ?CacheItemPoolInterface $cache = null
    ) {
        $this->fetcher = $fetcher;
        $this->cache = $cache;
        $this->cacheConfig = array_merge([
            'lifetime' => 1500,
            'prefix' => '',
            'cacheUniverseDomain' => $fetcher instanceof Credentials\GCECredentials,
        ], (array) $cacheConfig);
    }

    /**
     * @return FetchAuthTokenInterface
     */
    public function getFetcher()
    {
        return $this->fetcher;
    }

    /**
     * Implements FetchAuthTokenInterface#fetchAuthToken.
     *
     * Checks the cache for a valid auth token and fetches the auth tokens
     * from the supplied fetcher.
     *
     * @param callable|null $httpHandler callback which delivers psr7 request
     * @return array<mixed> the response
     * @throws \Exception
     */
    public function fetchAuthToken(?callable $httpHandler = null)
    {
        if ($cached = $this->fetchAuthTokenFromCache()) {
            return $cached;
        }

        $auth_token = $this->fetcher->fetchAuthToken($httpHandler);

        $this->saveAuthTokenInCache($auth_token);

        return $auth_token;
    }

    /**
     * @return string
     */
    public function getCacheKey()
    {
        return $this->getFullCacheKey($this->fetcher->getCacheKey());
    }

    /**
     * @return array<mixed>|null
     */
    public function getLastReceivedToken()
    {
        return $this->fetcher->getLastReceivedToken();
    }

    /**
     * Get the client name from the fetcher.
     *
     * @param callable|null $httpHandler An HTTP handler to deliver PSR7 requests.
     * @return string
     */
    public function getClientName(?callable $httpHandler = null)
    {
        if (!$this->fetcher instanceof SignBlobInterface) {
            throw new \RuntimeException(
                'Credentials fetcher does not implement ' .
                'Google\Auth\SignBlobInterface'
            );
        }

        return $this->fetcher->getClientName($httpHandler);
    }

    /**
     * Sign a blob using the fetcher.
     *
     * @param string $stringToSign The string to sign.
     * @param bool $forceOpenSsl Require use of OpenSSL for local signing. Does
     *        not apply to signing done using external services. **Defaults to**
     *        `false`.
     * @return string The resulting signature.
     * @throws \RuntimeException If the fetcher does not implement
     *     `Google\Auth\SignBlobInterface`.
     */
    public function signBlob($stringToSign, $forceOpenSsl = false)
    {
        if (!$this->fetcher instanceof SignBlobInterface) {
            throw new \RuntimeException(
                'Credentials fetcher does not implement ' .
                'Google\Auth\SignBlobInterface'
            );
        }

        
        
        
        if ($this->fetcher instanceof Credentials\GCECredentials
            || $this->fetcher instanceof Credentials\ImpersonatedServiceAccountCredentials
        ) {
            $cached = $this->fetchAuthTokenFromCache();
            $accessToken = $cached['access_token'] ?? null;
            return $this->fetcher->signBlob($stringToSign, $forceOpenSsl, $accessToken);
        }

        return $this->fetcher->signBlob($stringToSign, $forceOpenSsl);
    }

    /**
     * Get the quota project used for this API request from the credentials
     * fetcher.
     *
     * @return string|null
     */
    public function getQuotaProject()
    {
        if ($this->fetcher instanceof GetQuotaProjectInterface) {
            return $this->fetcher->getQuotaProject();
        }

        return null;
    }

    /**
     * Get the Project ID from the fetcher.
     *
     * @param callable|null $httpHandler Callback which delivers psr7 request
     * @return string|null
     * @throws \RuntimeException If the fetcher does not implement
     *     `Google\Auth\ProvidesProjectIdInterface`.
     */
    public function getProjectId(?callable $httpHandler = null)
    {
        if (!$this->fetcher instanceof ProjectIdProviderInterface) {
            throw new \RuntimeException(
                'Credentials fetcher does not implement ' .
                'Google\Auth\ProvidesProjectIdInterface'
            );
        }

        
        
        
        if ($this->fetcher instanceof Credentials\ExternalAccountCredentials) {
            $cached = $this->fetchAuthTokenFromCache();
            $accessToken = $cached['access_token'] ?? null;
            return $this->fetcher->getProjectId($httpHandler, $accessToken);
        }

        return $this->fetcher->getProjectId($httpHandler);
    }

    
    public function getUniverseDomain(): string
    {
        if ($this->fetcher instanceof GetUniverseDomainInterface) {
            if ($this->cacheConfig['cacheUniverseDomain']) {
                return $this->getCachedUniverseDomain($this->fetcher);
            }
            return $this->fetcher->getUniverseDomain();
        }

        return GetUniverseDomainInterface::DEFAULT_UNIVERSE_DOMAIN;
    }

    /**
     * Updates metadata with the authorization token.
     *
     * @param array<mixed> $metadata metadata hashmap
     * @param string $authUri optional auth uri
     * @param callable|null $httpHandler callback which delivers psr7 request
     * @return array<mixed> updated metadata hashmap
     * @throws \RuntimeException If the fetcher does not implement
     *     `Google\Auth\UpdateMetadataInterface`.
     */
    public function updateMetadata(
        $metadata,
        $authUri = null,
        ?callable $httpHandler = null
    ) {
        if (!$this->fetcher instanceof UpdateMetadataInterface) {
            throw new \RuntimeException(
                'Credentials fetcher does not implement ' .
                'Google\Auth\UpdateMetadataInterface'
            );
        }

        $cached = $this->fetchAuthTokenFromCache($authUri);
        if ($cached) {
            
            
            
            if (isset($cached['access_token'])) {
                $metadata[self::AUTH_METADATA_KEY] = [
                    'Bearer ' . $cached['access_token']
                ];
            } elseif (isset($cached['id_token'])) {
                $metadata[self::AUTH_METADATA_KEY] = [
                    'Bearer ' . $cached['id_token']
                ];
            }
        }

        $newMetadata = $this->fetcher->updateMetadata(
            $metadata,
            $authUri,
            $httpHandler
        );

        if (!$cached && $token = $this->fetcher->getLastReceivedToken()) {
            $this->saveAuthTokenInCache($token, $authUri);
        }

        return $newMetadata;
    }

    /**
     * @param string|null $authUri
     * @return array<mixed>|null
     */
    private function fetchAuthTokenFromCache($authUri = null)
    {
        
        
        
        
        
        

        
        $cacheKey = $authUri
            ? $this->getFullCacheKey($authUri)
            : $this->fetcher->getCacheKey();

        $cached = $this->getCachedValue($cacheKey);
        if (is_array($cached)) {
            if (empty($cached['expires_at'])) {
                
                
                return $cached;
            }
            if ((time() + $this->eagerRefreshThresholdSeconds) < $cached['expires_at']) {
                
                return $cached;
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $authToken
     * @param string|null  $authUri
     * @return void
     */
    private function saveAuthTokenInCache($authToken, $authUri = null)
    {
        if (isset($authToken['access_token']) ||
            isset($authToken['id_token'])) {
            
            $cacheKey = $authUri
                ? $this->getFullCacheKey($authUri)
                : $this->fetcher->getCacheKey();

            $this->setCachedValue($cacheKey, $authToken);
        }
    }

    private function getCachedUniverseDomain(GetUniverseDomainInterface $fetcher): string
    {
        $cacheKey = $this->getFullCacheKey($fetcher->getCacheKey() . 'universe_domain'); 
        if ($universeDomain = $this->getCachedValue($cacheKey)) {
            return $universeDomain;
        }

        $universeDomain = $fetcher->getUniverseDomain();
        $this->setCachedValue($cacheKey, $universeDomain);
        return $universeDomain;
    }
}
