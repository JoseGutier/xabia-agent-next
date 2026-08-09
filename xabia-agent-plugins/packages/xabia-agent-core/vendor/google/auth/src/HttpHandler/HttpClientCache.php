<?php

namespace Google\Auth\HttpHandler;

use GuzzleHttp\ClientInterface;

/**
 * Stores an HTTP Client in order to prevent multiple instantiations.
 */
class HttpClientCache
{
    /**
     * @var ClientInterface|null
     */
    private static $httpClient;

    /**
     * Cache an HTTP Client for later calls.
     *
     * Passing null will unset the cached client.
     *
     * @param ClientInterface|null $client
     * @return void
     */
    public static function setHttpClient(?ClientInterface $client = null)
    {
        self::$httpClient = $client;
    }

    /**
     * Get the stored HTTP Client, or null.
     *
     * @return ClientInterface|null
     */
    public static function getHttpClient()
    {
        return self::$httpClient;
    }
}
