<?php

namespace Google\Auth;

/**
 * Describes a Credentials object which supports updating request metadata
 * (request headers).
 */
interface UpdateMetadataInterface
{
    const AUTH_METADATA_KEY = 'authorization';

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
    );
}
