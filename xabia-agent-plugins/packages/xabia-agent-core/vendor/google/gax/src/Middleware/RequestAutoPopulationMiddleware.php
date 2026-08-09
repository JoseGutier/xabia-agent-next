<?php

namespace Google\ApiCore\Middleware;

use Google\Api\FieldInfo\Format;
use Google\ApiCore\Call;
use GuzzleHttp\Promise\PromiseInterface;
use Ramsey\Uuid\Uuid;

/**
 * Middleware that adds autopopulation functionality. This middlware is
 * added iff auto population settings are present in the resource
 * descriptor config for the rpc method in context.
 *
 * @internal
 */
class RequestAutoPopulationMiddleware implements MiddlewareInterface
{
    /** @var callable */
    private $nextHandler;

    /** @var array<string, string> */
    private $autoPopulationSettings;

    public function __construct(
        callable $nextHandler,
        array $autoPopulationSettings
    ) {
        $this->nextHandler = $nextHandler;
        $this->autoPopulationSettings = $autoPopulationSettings;
    }

    /**
     * @param Call $call
     * @param array $options
     *
     * @return PromiseInterface
     */
    public function __invoke(Call $call, array $options)
    {
        $next = $this->nextHandler;

        if (empty($this->autoPopulationSettings)) {
            return $next($call, $options);
        }

        $request = $call->getMessage();
        foreach ($this->autoPopulationSettings as $fieldName => $valueType) {
            $getFieldName = 'get' . ucwords($fieldName);
            
            
            
            if (empty($request->$getFieldName())) {
                $setFieldName = 'set' . ucwords($fieldName);
                switch ($valueType) {
                    case Format::UUID4:
                        $request->$setFieldName(Uuid::uuid4()->toString());
                        break;
                    default:
                        throw new \UnexpectedValueException(sprintf(
                            'Value type %s::%s not supported for auto population of the field %s',
                            Format::class,
                            Format::name($valueType),
                            $fieldName
                        ));
                }
            }
        }
        $call = $call->withMessage($request);
        return $next(
            $call,
            $options
        );
    }
}
