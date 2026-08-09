<?php

namespace Google\ApiCore;

use Generator;
use Google\Protobuf\Internal\MapField;
use Google\Protobuf\Internal\Message;
use IteratorAggregate;

/**
 * A Page object wraps an API list method response and provides methods
 * to retrieve additional pages using the page token.
 */
class Page implements IteratorAggregate
{
    const FINAL_PAGE_TOKEN = '';

    private $call;
    private $callable;
    private $options;
    private $pageStreamingDescriptor;

    private $pageToken; 

    private $response;

    /**
     * Page constructor.
     *
     * @param Call $call
     * @param array $options
     * @param callable $callable
     * @param PageStreamingDescriptor $pageStreamingDescriptor
     * @param Message $response
     */
    public function __construct(
        Call $call,
        array $options,
        callable $callable,
        PageStreamingDescriptor $pageStreamingDescriptor,
        Message $response
    ) {
        $this->call = $call;
        $this->options = $options;
        $this->callable = $callable;
        $this->pageStreamingDescriptor = $pageStreamingDescriptor;
        $this->response = $response;

        $requestPageTokenGetMethod = $this->pageStreamingDescriptor->getRequestPageTokenGetMethod();
        $this->pageToken = $this->call->getMessage()->$requestPageTokenGetMethod();
    }

    /**
     * Returns true if there are more pages that can be retrieved from the
     * API.
     *
     * @return bool
     */
    public function hasNextPage()
    {
        return strcmp($this->getNextPageToken(), Page::FINAL_PAGE_TOKEN) != 0;
    }

    /**
     * Returns the next page token from the response.
     *
     * @return string
     */
    public function getNextPageToken()
    {
        $responsePageTokenGetMethod = $this->pageStreamingDescriptor->getResponsePageTokenGetMethod();
        return $this->getResponseObject()->$responsePageTokenGetMethod();
    }

    /**
     * Retrieves the next Page object using the next page token.
     *
     * @param int|null $pageSize
     * @throws ValidationException if there are no pages remaining, or if pageSize is supplied but
     * is not supported by the API
     * @throws ApiException if the call to fetch the next page fails.
     * @return Page
     */
    public function getNextPage(?int $pageSize = null)
    {
        if (!$this->hasNextPage()) {
            throw new ValidationException(
                'Could not complete getNextPage operation: ' .
                'there are no more pages to retrieve.'
            );
        }

        $oldRequest = $this->getRequestObject();
        $requestClass = get_class($oldRequest);
        $newRequest = new $requestClass();
        $newRequest->mergeFrom($oldRequest);

        $requestPageTokenSetMethod = $this->pageStreamingDescriptor->getRequestPageTokenSetMethod();
        $newRequest->$requestPageTokenSetMethod($this->getNextPageToken());

        if (isset($pageSize)) {
            if (!$this->pageStreamingDescriptor->requestHasPageSizeField()) {
                throw new ValidationException(
                    'pageSize argument was defined, but the method does not ' .
                    'support a page size parameter in the optional array argument'
                );
            }
            $requestPageSizeSetMethod = $this->pageStreamingDescriptor->getRequestPageSizeSetMethod();
            $newRequest->$requestPageSizeSetMethod($pageSize);
        }
        $this->call = $this->call->withMessage($newRequest);

        $callable = $this->callable;
        $response = $callable(
            $this->call,
            $this->options
        )->wait();

        return new Page(
            $this->call,
            $this->options,
            $this->callable,
            $this->pageStreamingDescriptor,
            $response
        );
    }

    /**
     * Return the number of elements in the response.
     *
     * @return int
     */
    public function getPageElementCount()
    {
        $resourcesGetMethod = $this->pageStreamingDescriptor->getResourcesGetMethod();
        return count($this->getResponseObject()->$resourcesGetMethod());
    }

    /**
     * Return an iterator over the elements in the response.
     *
     * @return Generator
     */
    #[\ReturnTypeWillChange]
    public function getIterator()
    {
        $resourcesGetMethod = $this->pageStreamingDescriptor->getResourcesGetMethod();
        $items = $this->getResponseObject()->$resourcesGetMethod();
        foreach ($items as $key => $element) {
            if ($items instanceof MapField) {
                yield $key => $element;
            } else {
                yield $element;
            }
        }
    }

    /**
     * Return an iterator over Page objects, beginning with this object.
     * Additional Page objects are retrieved lazily via API calls until
     * all elements have been retrieved.
     *
     * @return Generator|array<Page>
     * @throws ValidationException
     * @throws ApiException
     */
    public function iteratePages()
    {
        $currentPage = $this;
        yield $this;
        while ($currentPage->hasNextPage()) {
            $currentPage = $currentPage->getNextPage();
            yield $currentPage;
        }
    }

    /**
     * Gets the request object used to generate the Page.
     *
     * @return mixed|Message
     */
    public function getRequestObject()
    {
        return $this->call->getMessage();
    }

    /**
     * Gets the API response object.
     *
     * @return mixed|Message
     */
    public function getResponseObject()
    {
        return $this->response;
    }

    /**
     * Returns a collection of elements with a fixed size set by
     * the collectionSize parameter. The collection will only contain
     * fewer than collectionSize elements if there are no more
     * pages to be retrieved from the server.
     *
     * NOTE: it is an error to call this method if an optional parameter
     * to set the page size is not supported or has not been set in the
     * API call that was used to create this page. It is also an error
     * if the collectionSize parameter is less than the page size that
     * has been set.
     *
     * @param int $collectionSize
     * @throws ValidationException if a FixedSizeCollection of the specified size cannot be constructed
     * @return FixedSizeCollection
     */
    public function expandToFixedSizeCollection($collectionSize)
    {
        if (!$this->pageStreamingDescriptor->requestHasPageSizeField()) {
            throw new ValidationException(
                'FixedSizeCollection is not supported for this method, because ' .
                'the method does not support an optional argument to set the ' .
                'page size.'
            );
        }
        $request = $this->getRequestObject();
        $pageSizeGetMethod = $this->pageStreamingDescriptor->getRequestPageSizeGetMethod();
        $pageSize = $request->$pageSizeGetMethod();
        if (is_null($pageSize)) {
            throw new ValidationException(
                'Error while expanding Page to FixedSizeCollection: No page size ' .
                'parameter found. The page size parameter must be set in the API ' .
                'optional arguments array, and must be less than the collectionSize ' .
                'parameter, in order to create a FixedSizeCollection object.'
            );
        }
        if ($pageSize > $collectionSize) {
            throw new ValidationException(
                'Error while expanding Page to FixedSizeCollection: collectionSize ' .
                'parameter is less than the page size optional argument specified in ' .
                "the API call. collectionSize: $collectionSize, page size: $pageSize"
            );
        }
        return new FixedSizeCollection($this, $collectionSize);
    }
}
