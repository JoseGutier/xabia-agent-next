<?php

namespace Google\ApiCore;

/**
 * Provides helper methods for service address handling.
 *
 * @deprecated
 * @todo (dwsupplee) serviceAddress is deprecated now in favor of
 *        apiEndpoint. Rename the trait/method in our next major release.
 */
trait ServiceAddressTrait
{
    private static $defaultPort = 443;

    /**
     * @param string $apiEndpoint
     * @return array
     * @throws ValidationException
     */
    private static function normalizeServiceAddress(string $apiEndpoint)
    {
        $components = explode(':', $apiEndpoint);
        if (count($components) == 2) {
            
            return [$components[0], $components[1]];
        } elseif (count($components) == 1) {
            
            return [$components[0], self::$defaultPort];
        } else {
            throw new ValidationException("Invalid apiEndpoint: $apiEndpoint");
        }
    }
}
