<?php

namespace Google\ApiCore;

/**
 * For connect to emulator.
 * @TODO: implement HeaderCredentialsInterface instead of extending CredentialsWrapper
 */
class InsecureCredentialsWrapper extends CredentialsWrapper
{
    public function __construct()
    {
    }

    /**
     * @param string $audience
     * @return callable|null Returns null so the gRPC can accept it as an insecure channel.
     */
    public function getAuthorizationHeaderCallback($audience = null): ?callable
    {
        return null;
    }

    public function checkUniverseDomain(): void
    {
    }
}
