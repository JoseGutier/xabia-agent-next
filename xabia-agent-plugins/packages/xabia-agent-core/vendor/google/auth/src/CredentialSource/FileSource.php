<?php

namespace Google\Auth\CredentialSource;

use Google\Auth\ExternalAccountCredentialSourceInterface;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Retrieve a token from a file.
 */
class FileSource implements ExternalAccountCredentialSourceInterface
{
    private string $file;
    private ?string $format;
    private ?string $subjectTokenFieldName;

    /**
     * @param string $file                       The file to read the subject token from.
     * @param string|null $format                The format of the token in the file. Can be null or "json".
     * @param string|null $subjectTokenFieldName The name of the field containing the token in the file. This is required
     *                                           when format is "json".
     */
    public function __construct(
        string $file,
        ?string $format = null,
        ?string $subjectTokenFieldName = null
    ) {
        $this->file = $file;

        if ($format === 'json' && is_null($subjectTokenFieldName)) {
            throw new InvalidArgumentException(
                'subject_token_field_name must be set when format is JSON'
            );
        }

        $this->format = $format;
        $this->subjectTokenFieldName = $subjectTokenFieldName;
    }

    public function fetchSubjectToken(?callable $httpHandler = null): string
    {
        $contents = file_get_contents($this->file);
        if ($this->format === 'json') {
            if (!$json = json_decode((string) $contents, true)) {
                throw new UnexpectedValueException(
                    'Unable to decode JSON file'
                );
            }
            if (!isset($json[$this->subjectTokenFieldName])) {
                throw new UnexpectedValueException(
                    'subject_token_field_name not found in JSON file'
                );
            }
            $contents = $json[$this->subjectTokenFieldName];
        }

        return $contents;
    }

    /**
     * Gets the unique key for caching.
     * The format for the cache key one of the following:
     * Filename
     *
     * @return string
     */
    public function getCacheKey(): ?string
    {
        return $this->file;
    }
}
