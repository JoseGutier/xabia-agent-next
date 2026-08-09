<?php

namespace Google\ApiCore;

/**
 * Encapsulates request params header metadata.
 */
class RequestParamsHeaderDescriptor
{
    const HEADER_KEY = 'x-goog-request-params';

    /**
     * @var array
     */
    private $header;

    /**
     * RequestParamsHeaderDescriptor constructor.
     *
     * @param array $requestParams An associative array which contains request params header data in
     * a form ['field_name.subfield_name' => value].
     */
    public function __construct(array $requestParams)
    {
        $headerKey = self::HEADER_KEY;

        $headerValue = '';
        foreach ($requestParams as $key => $value) {
            if ('' !== $headerValue) {
                $headerValue .= '&';
            }

            $headerValue .= $key . '=' . urlencode(strval($value));
        }

        $this->header = [$headerKey => [$headerValue]];
    }

    /**
     * Returns an associative array that contains request params header metadata.
     *
     * @return array
     */
    public function getHeader()
    {
        return $this->header;
    }
}
