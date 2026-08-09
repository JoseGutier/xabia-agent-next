<?php

namespace Google\ApiCore;

use Generator;
use InvalidArgumentException;
use IteratorAggregate;
use LengthException;

/**
 * A collection of elements retrieved using one or more API calls. The
 * collection will attempt to retrieve a fixed number of elements, and
 * will make API calls until that fixed number is reached, or there
 * are no more elements to retrieve.
 */
class FixedSizeCollection implements IteratorAggregate
{
    private $collectionSize;
    private $pageList;

    /**
     * FixedSizeCollection constructor.
     * @param Page $initialPage
     * @param int $collectionSize
     */
    public function __construct(Page $initialPage, int $collectionSize)
    {
        if ($collectionSize <= 0) {
            throw new InvalidArgumentException(
                "collectionSize must be > 0. collectionSize: $collectionSize"
            );
        }
        if ($collectionSize < $initialPage->getPageElementCount()) {
            $ipc = $initialPage->getPageElementCount();
            throw new InvalidArgumentException(
                'collectionSize must be greater than or equal to the number of ' .
                "elements in initialPage. collectionSize: $collectionSize, " .
                "initialPage size: $ipc"
            );
        }
        $this->collectionSize = $collectionSize;

        $this->pageList = FixedSizeCollection::createPageArray($initialPage, $collectionSize);
    }

    /**
     * Returns the number of elements in the collection. This will be
     * equal to the collectionSize parameter used at construction
     * unless there are no elements remaining to be retrieved.
     *
     * @return int
     */
    public function getCollectionSize()
    {
        $size = 0;
        foreach ($this->pageList as $page) {
            $size += $page->getPageElementCount();
        }
        return $size;
    }

    /**
     * Returns true if there are more elements that can be retrieved
     * from the API.
     *
     * @return bool
     */
    public function hasNextCollection()
    {
        return $this->getLastPage()->hasNextPage();
    }

    /**
     * Returns a page token that can be passed into the API list
     * method to retrieve additional elements.
     *
     * @return string
     */
    public function getNextPageToken()
    {
        return $this->getLastPage()->getNextPageToken();
    }

    /**
     * Retrieves the next FixedSizeCollection using one or more API calls.
     *
     * @return FixedSizeCollection
     */
    public function getNextCollection()
    {
        $lastPage = $this->getLastPage();
        $nextPage = $lastPage->getNextPage($this->collectionSize);
        return new FixedSizeCollection($nextPage, $this->collectionSize);
    }

    /**
     * Returns an iterator over the elements of the collection.
     *
     * @return Generator
     */
    #[\ReturnTypeWillChange]
    public function getIterator()
    {
        foreach ($this->pageList as $page) {
            foreach ($page as $element) {
                yield $element;
            }
        }
    }

    /**
     * Returns an iterator over FixedSizeCollections, starting with this
     * and making API calls as required until all of the elements have
     * been retrieved.
     *
     * @return Generator|FixedSizeCollection[]
     */
    public function iterateCollections()
    {
        $currentCollection = $this;
        yield $this;
        while ($currentCollection->hasNextCollection()) {
            $currentCollection = $currentCollection->getNextCollection();
            yield $currentCollection;
        }
    }

    private function getLastPage()
    {
        $pageList = $this->pageList;
        
        $lastPage = end($pageList);
        reset($pageList);
        return $lastPage;
    }

    /**
     * @param Page $initialPage
     * @param int $collectionSize
     * @return Page[]
     */
    private static function createPageArray(Page $initialPage, int $collectionSize)
    {
        $pageList = [$initialPage];
        $currentPage = $initialPage;
        $itemCount = $currentPage->getPageElementCount();
        while ($itemCount < $collectionSize && $currentPage->hasNextPage()) {
            $remainingCount = $collectionSize - $itemCount;
            $currentPage = $currentPage->getNextPage($remainingCount);
            $rxElementCount = $currentPage->getPageElementCount();
            if ($rxElementCount > $remainingCount) {
                throw new LengthException('API returned a number of elements ' .
                    'exceeding the specified page size limit. page size: ' .
                    "$remainingCount, elements received: $rxElementCount");
            }
            array_push($pageList, $currentPage);
            $itemCount += $rxElementCount;
        }
        return $pageList;
    }
}
