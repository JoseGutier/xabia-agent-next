<?php

namespace Google\Auth\CredentialSource;

use Google\Auth\ExternalAccountCredentialSourceInterface;
use Google\Auth\HttpHandler\HttpClientCache;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use GuzzleHttp\Psr7\Request;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Retrieve a token from a URL.
 */
class UrlSource implements ExternalAccountCredentialSourceInterface
{
    private string $url;
    private ?string $format;
    private ?string $subjectTokenFieldName;

    /**
     * @var array<string, string|string[]>
     */
    private ?array $headers;

    /**
     * @param string $url                        The URL to fetch the subject token from.
     * @param string|null $format                The format of the token in the response. Can be null or "json".
     * @param string|null $subjectTokenFieldName The name of the field containing the token in the response. This is required
     *                                      when format is "json".
     * @param array<string, string|string[]>|null $headers Request headers to send in with the request to the URL.
     */
    public function __construct(
        string $url,
        ?string $format = null,
        ?string $subjectTokenFieldName = null,
        ?array $headers = null
    ) {
        $this->url = $url;

        if ($format === 'json' && is_null($subjectTokenFieldName)) {
            throw new InvalidArgumentException(
                'subject_token_field_name must be set when format is JSON'
            );
        }

        $this->format = $format;
        $this->subjectTokenFieldName = $subjectTokenFieldName;
        $this->headers = $headers;
    }

    public function fetchSubjectToken(?callable $httpHandler = null): string
    {
        if (is_null($httpHandler)) {
            $httpHandler = HttpHandlerFactory::build(HttpClientCache::getHttpClient());
        }

        $request = new Request(
            'GET',
            $this->url,
            $this->headers ?: []
        );

        $response = $httpHandler($request);
        $body = (string) $response->getBody();
        if ($this->format === 'json') {
            if (!$json = json_decode((string) $body, true)) {
                throw new UnexpectedValueException(
                    'Unable to decode JSON response'
                );
            }
            if (!isset($json[$this->subjectTokenFieldName])) {
                throw new UnexpectedValueException(
                    'subject_token_field_name not found in JSON file'
                );
            }
            $body = $json[$this->subjectTokenFieldName];
        }

        return $body;
    }

    /**
     * Get the cache key for the credentials.
     * The format for the cache key is:
     * URL
     *
     * @return ?string
     */
    public function getCacheKey(): ?string
    {
        return $this->url;
    }
}
