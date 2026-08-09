<?php

namespace Google\ApiCore\Middleware;

use Google\ApiCore\Call;
use Google\ApiCore\OperationResponse;
use Google\Protobuf\Internal\Message;

/**
 * Middleware which wraps the response in an OperationResponse object.
 *
 * @internal
 */
class OperationsMiddleware implements MiddlewareInterface
{
    /** @var callable */
    private $nextHandler;
    private object $operationsClient;
    private array $descriptor;

    public function __construct(
        callable $nextHandler,
        $operationsClient,
        array $descriptor
    ) {
        $this->nextHandler = $nextHandler;
        $this->operationsClient = $operationsClient;
        $this->descriptor = $descriptor;
    }

    public function __invoke(Call $call, array $options)
    {
        $next = $this->nextHandler;
        return $next(
            $call,
            $options
        )->then(function (Message $response) {
            $options = $this->descriptor + [
                'lastProtoResponse' => $response
            ];
            $operationNameMethod = $options['operationNameMethod'] ?? 'getName';
            $operationName = call_user_func([$response, $operationNameMethod]);
            return new OperationResponse($operationName, $this->operationsClient, $options);
        });
    }
}
