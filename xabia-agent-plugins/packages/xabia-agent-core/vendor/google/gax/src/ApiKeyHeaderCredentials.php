<?php

namespace Google\ApiCore;

use Google\Auth\GetQuotaProjectInterface;

/**
 * The ApiKeyHeaderCredentials object provides a wrapper around an API key.
 */
class ApiKeyHeaderCredentials implements HeaderCredentialsInterface, GetQuotaProjectInterface
{
    private string $apiKey;
    private ?string $quotaProject;

    /**
     * ApiKeyHeaderCredentials constructor.
     * @param string $apiKey The API key to set in the header for the request
     * @param string|null $quotaProject The quota project associated with the API key.
     * @throws ValidationException
     */
    public function __construct(string $apiKey, ?string $quotaProject = null)
    {
        if (empty($apiKey)) {
            throw new ValidationException('API key cannot be empty');
        }
        $this->apiKey = $apiKey;
        $this->quotaProject = $quotaProject;
    }

    /**
     * @return string|null The quota project associated with the credentials.
     */
    public function getQuotaProject(): ?string
    {
        return $this->quotaProject;
    }

    /**
     * @param string|null $unusedAudience audiences are not supported for API keys.
     *
     * @return callable|null Callable function that returns the API key header.
     */
    public function getAuthorizationHeaderCallback(?string $unusedAudience = null): ?callable
    {
        $apiKey = $this->apiKey;

        
        
        
        return function () use ($apiKey) {
            return ['x-goog-api-key' => [$apiKey]];
        };
    }

    /**
     * Verify that the expected universe domain matches the universe domain from the credentials.
     *
     * @throws ValidationException if the universe domain does not match.
     */
    public function checkUniverseDomain(): void
    {
        
        
        
    }
}
