<?php

namespace Google\Auth\Credentials;

/**
 * Authenticates requests using IAM credentials.
 */
class IAMCredentials
{
    const SELECTOR_KEY = 'x-goog-iam-authority-selector';
    const TOKEN_KEY = 'x-goog-iam-authorization-token';

    /**
     * @var string
     */
    private $selector;

    /**
     * @var string
     */
    private $token;

    /**
     * @param string $selector the IAM selector
     * @param string $token the IAM token
     */
    public function __construct($selector, $token)
    {
        if (!is_string($selector)) {
            throw new \InvalidArgumentException(
                'selector must be a string'
            );
        }
        if (!is_string($token)) {
            throw new \InvalidArgumentException(
                'token must be a string'
            );
        }

        $this->selector = $selector;
        $this->token = $token;
    }

    /**
     * export a callback function which updates runtime metadata.
     *
     * @return callable updateMetadata function
     */
    public function getUpdateMetadataFunc()
    {
        return [$this, 'updateMetadata'];
    }

    /**
     * Updates metadata with the appropriate header metadata.
     *
     * @param array<mixed> $metadata metadata hashmap
     * @param string $unusedAuthUri optional auth uri
     * @param callable|null $httpHandler callback which delivers psr7 request
     *        Note: this param is unused here, only included here for
     *        consistency with other credentials class
     *
     * @return array<mixed> updated metadata hashmap
     */
    public function updateMetadata(
        $metadata,
        $unusedAuthUri = null,
        ?callable $httpHandler = null
    ) {
        $metadata_copy = $metadata;
        $metadata_copy[self::SELECTOR_KEY] = $this->selector;
        $metadata_copy[self::TOKEN_KEY] = $this->token;

        return $metadata_copy;
    }
}
