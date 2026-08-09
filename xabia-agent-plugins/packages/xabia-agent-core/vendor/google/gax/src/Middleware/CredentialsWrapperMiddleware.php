<?php

namespace Google\ApiCore\Middleware;

use Google\ApiCore\Call;
use Google\ApiCore\CredentialsWrapper;
use Google\ApiCore\HeaderCredentialsInterface;

/**
* Middleware which adds a CredentialsWrapper object to the call options.
*
* @internal
*/
class CredentialsWrapperMiddleware implements MiddlewareInterface
{
    /** @var callable */
    private $nextHandler;

    /** @var HeaderCredentialsInterface */
    private HeaderCredentialsInterface  $credentialsWrapper;

    public function __construct(
        callable $nextHandler,
        HeaderCredentialsInterface $credentialsWrapper
    ) {
        $this->nextHandler = $nextHandler;
        $this->credentialsWrapper = $credentialsWrapper;
    }

    public function __invoke(Call $call, array $options)
    {
        $next = $this->nextHandler;
        return $next(
            $call,
            $options + ['credentialsWrapper' => $this->credentialsWrapper]
        );
    }
}
