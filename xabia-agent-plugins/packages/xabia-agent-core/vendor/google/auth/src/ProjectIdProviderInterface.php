<?php

namespace Google\Auth;

/**
 * Describes a Credentials object which supports fetching the project ID.
 */
interface ProjectIdProviderInterface
{
    /**
     * Get the project ID.
     *
     * @param callable|null $httpHandler Callback which delivers psr7 request
     * @return string|null
     */
    public function getProjectId(?callable $httpHandler = null);
}
