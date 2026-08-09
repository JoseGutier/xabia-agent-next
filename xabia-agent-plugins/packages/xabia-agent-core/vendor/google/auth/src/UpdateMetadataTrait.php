<?php

namespace Google\Auth;

/**
 * Provides shared methods for updating request metadata (request headers).
 *
 * Should implement {@see UpdateMetadataInterface} and {@see FetchAuthTokenInterface}.
 *
 * @internal
 */
trait UpdateMetadataTrait
{
    use MetricsTrait;

    /**
     * export a callback function which updates runtime metadata.
     *
     * @return callable updateMetadata function
     * @deprecated
     */
    public function getUpdateMetadataFunc()
    {
        return [$this, 'updateMetadata'];
    }

    /**
     * Updates metadata with the authorization token.
     *
     * @param array<mixed> $metadata metadata hashmap
     * @param string $authUri optional auth uri
     * @param callable|null $httpHandler callback which delivers psr7 request
     * @return array<mixed> updated metadata hashmap
     */
    public function updateMetadata(
        $metadata,
        $authUri = null,
        ?callable $httpHandler = null
    ) {
        $metadata_copy = $metadata;

        
        
        
        $metadata_copy = $this->applyServiceApiUsageMetrics($metadata_copy);

        if (isset($metadata_copy[self::AUTH_METADATA_KEY])) {
            
            return $metadata_copy;
        }
        $result = $this->fetchAuthToken($httpHandler);
        if (isset($result['access_token'])) {
            $metadata_copy[self::AUTH_METADATA_KEY] = ['Bearer ' . $result['access_token']];
        } elseif (isset($result['id_token'])) {
            $metadata_copy[self::AUTH_METADATA_KEY] = ['Bearer ' . $result['id_token']];
        }
        return $metadata_copy;
    }
}
