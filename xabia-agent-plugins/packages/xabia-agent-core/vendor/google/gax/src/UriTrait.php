<?php

namespace Google\ApiCore;

use GuzzleHttp\Psr7\Query;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\UriInterface;

/**
 * Provides a light wrapper around often used URI related functions.
 *
 * @internal
 */
trait UriTrait
{
    /**
     * @param string|UriInterface $uri
     * @param array $query
     * @return UriInterface
     */
    public function buildUriWithQuery($uri, array $query)
    {
        $query = array_filter($query, function ($v) {
            return $v !== null;
        });

        
        foreach ($query as $k => &$v) {
            if (is_bool($v)) {
                $v = $v ? 'true' : 'false';
            }
        }

        return Utils::uriFor($uri)
            ->withQuery(
                Query::build($query)
            );
    }
}
