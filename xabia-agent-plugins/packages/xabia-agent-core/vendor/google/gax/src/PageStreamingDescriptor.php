<?php

namespace Google\ApiCore;

use InvalidArgumentException;

/**
 * Holds the description information used for page streaming.
 */
class PageStreamingDescriptor
{
    private $descriptor;

    /**
     * @param array $descriptor {
     *     Required.
     *     @type string $requestPageTokenGetMethod the get method for the page token in the request object.
     *     @type string $requestPageTokenSetMethod the set method for the page token in the request object.
     *     @type string $responsePageTokenGetMethod the get method for the page token in the response object.
     *     @type string resourcesGetMethod the get method for the resources in the response object.
     *
     *     Optional.
     *     @type string $requestPageSizeGetMethod the get method for the page size in the request object.
     * }
     */
    public function __construct(array $descriptor)
    {
        self::validate($descriptor);
        $this->descriptor = $descriptor;
    }

    /**
     * @param array $fields {
     *     Required.
     *
     *     @type string $requestPageTokenField the page token field in the request object.
     *     @type string $responsePageTokenField the page token field in the response object.
     *     @type string $resourceField the resource field in the response object.
     *
     *     Optional.
     *     @type string $requestPageSizeField the page size field in the request object.
     * }
     * @return PageStreamingDescriptor
     */
    public static function createFromFields(array $fields)
    {
        $requestPageToken = $fields['requestPageTokenField'];
        $responsePageToken = $fields['responsePageTokenField'];
        $resources = $fields['resourceField'];

        $descriptor = [
            'requestPageTokenGetMethod' => PageStreamingDescriptor::getMethod($requestPageToken),
            'requestPageTokenSetMethod' => PageStreamingDescriptor::setMethod($requestPageToken),
            'responsePageTokenGetMethod' => PageStreamingDescriptor::getMethod($responsePageToken),
            'resourcesGetMethod' => PageStreamingDescriptor::getMethod($resources),
        ];

        if (isset($fields['requestPageSizeField'])) {
            $requestPageSize = $fields['requestPageSizeField'];
            $descriptor['requestPageSizeGetMethod'] = PageStreamingDescriptor::getMethod($requestPageSize);
            $descriptor['requestPageSizeSetMethod'] = PageStreamingDescriptor::setMethod($requestPageSize);
        }

        return new PageStreamingDescriptor($descriptor);
    }

    private static function getMethod(string $field)
    {
        return 'get' . ucfirst($field);
    }

    private static function setMethod(string $field)
    {
        return 'set' . ucfirst($field);
    }

    /**
     * @return string The page token get method on the request object
     */
    public function getRequestPageTokenGetMethod()
    {
        return $this->descriptor['requestPageTokenGetMethod'];
    }

    /**
     * @return string The page size get method on the request object
     */
    public function getRequestPageSizeGetMethod()
    {
        return $this->descriptor['requestPageSizeGetMethod'];
    }

    /**
     * @return bool True if the request object has a page size field
     */
    public function requestHasPageSizeField()
    {
        return array_key_exists('requestPageSizeGetMethod', $this->descriptor);
    }

    /**
     * @return string The page token get method on the response object
     */
    public function getResponsePageTokenGetMethod()
    {
        return $this->descriptor['responsePageTokenGetMethod'];
    }

    /**
     * @return string The resources get method on the response object
     */
    public function getResourcesGetMethod()
    {
        return $this->descriptor['resourcesGetMethod'];
    }

    /**
     * @return string The page token set method on the request object
     */
    public function getRequestPageTokenSetMethod()
    {
        return $this->descriptor['requestPageTokenSetMethod'];
    }

    /**
     * @return string The page size set method on the request object
     */
    public function getRequestPageSizeSetMethod()
    {
        return $this->descriptor['requestPageSizeSetMethod'];
    }

    private static function validate(array $descriptor)
    {
        $requiredFields = [
            'requestPageTokenGetMethod',
            'requestPageTokenSetMethod',
            'responsePageTokenGetMethod',
            'resourcesGetMethod',
        ];
        foreach ($requiredFields as $field) {
            if (empty($descriptor[$field])) {
                throw new InvalidArgumentException(
                    "$field is required for PageStreamingDescriptor"
                );
            }
        }
    }
}
