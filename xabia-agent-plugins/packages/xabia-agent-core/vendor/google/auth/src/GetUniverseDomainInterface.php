<?php

namespace Google\Auth;

/**
 * An interface implemented by objects that can get universe domain for Google Cloud APIs.
 */
interface GetUniverseDomainInterface
{
    const DEFAULT_UNIVERSE_DOMAIN = 'googleapis.com';

    /**
     * Get the universe domain from the credential. This should always return
     * a string, and default to "googleapis.com" if no universe domain is
     * configured.
     *
     * @return string
     */
    public function getUniverseDomain(): string;
}
