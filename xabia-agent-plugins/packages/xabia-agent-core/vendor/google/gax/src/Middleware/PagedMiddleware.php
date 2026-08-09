<?php

namespace Google\ApiCore\Middleware;

use Google\ApiCore\Call;
use Google\ApiCore\Page;
use Google\ApiCore\PagedListResponse;
use Google\ApiCore\PageStreamingDescriptor;
use Google\Protobuf\Internal\Message;

/**
* Middleware which wraps the response in an PagedListResponses object.
*
* @internal
*/
class PagedMiddleware implements MiddlewareInterface
{
    /** @var callable */
    private $nextHandler;
    private PageStreamingDescriptor $descriptor;

    /**
     * @param callable $nextHandler
     * @param PageStreamingDescriptor $descriptor
     */
    public function __construct(
        callable $nextHandler,
        PageStreamingDescriptor $descriptor
    ) {
        $this->nextHandler = $nextHandler;
        $this->descriptor = $descriptor;
    }

    public function __invoke(Call $call, array $options)
    {
        $next = $this->nextHandler;
        $descriptor = $this->descriptor;
        return $next($call, $options)->then(
            function (Message $response) use ($call, $next, $options, $descriptor) {
                $page = new Page(
                    $call,
                    $options,
                    $next,
                    $descriptor,
                    $response
                );
                return new PagedListResponse($page);
            }
        );
    }
}
