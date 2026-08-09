<?php

namespace Google\ApiCore;

interface HeaderCredentialsInterface
{
    /**
     * @param string|null $audience optional audience for self-signed JWTs.
     * @return callable|null Callable function that returns an authorization header.
     */
    public function getAuthorizationHeaderCallback(?string $audience = null): ?callable;

    /**
     * Verify that the expected universe domain matches the universe domain from the credentials.
     *
     * @throws ValidationException if the universe domain does not match.
     */
    public function checkUniverseDomain(): void;
}
