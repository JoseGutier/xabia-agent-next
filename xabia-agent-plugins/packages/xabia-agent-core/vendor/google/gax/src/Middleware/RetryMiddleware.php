<?php

namespace Google\ApiCore\Middleware;

use Google\ApiCore\ApiException;
use Google\ApiCore\ApiStatus;
use Google\ApiCore\Call;
use Google\ApiCore\RetrySettings;
use GuzzleHttp\Promise\PromiseInterface;

/**
 * Middleware that adds retry functionality.
 *
 * @internal
 */
class RetryMiddleware implements MiddlewareInterface
{
    /** @var callable */
    private $nextHandler;
    private RetrySettings $retrySettings;
    private ?float $deadlineMs;

    
    private int $retryAttempts;

    public function __construct(
        callable $nextHandler,
        RetrySettings $retrySettings,
        $deadlineMs = null,
        $retryAttempts = 0
    ) {
        $this->nextHandler = $nextHandler;
        $this->retrySettings = $retrySettings;
        $this->deadlineMs = $deadlineMs;
        $this->retryAttempts = $retryAttempts;
    }

    /**
     * @param Call $call
     * @param array $options
     *
     * @return PromiseInterface
     */
    public function __invoke(Call $call, array $options)
    {
        $nextHandler = $this->nextHandler;

        if (!isset($options['timeoutMillis'])) {
            
            if (!$this->retrySettings->retriesEnabled() && $this->retrySettings->getNoRetriesRpcTimeoutMillis() > 0) {
                $options['timeoutMillis'] = $this->retrySettings->getNoRetriesRpcTimeoutMillis();
            } elseif ($this->retrySettings->getInitialRpcTimeoutMillis() > 0) {
                $options['timeoutMillis'] = $this->retrySettings->getInitialRpcTimeoutMillis();
            }
        }

        
        if ($this->retryAttempts > 0) {
            $options['retryAttempt'] = $this->retryAttempts;
        }

        
        if (!$this->retrySettings->retriesEnabled()) {
            return $nextHandler($call, $options);
        }

        return $nextHandler($call, $options)->then(null, function ($e) use ($call, $options) {
            $retryFunction = $this->getRetryFunction();

            
            
            
            if (0 !== $this->retrySettings->getMaxRetries()
                && $this->retryAttempts >= $this->retrySettings->getMaxRetries()
            ) {
                throw $e;
            }
            
            
            if (!$retryFunction($e, $options)) {
                throw $e;
            }

            
            return $this->retry($call, $options, $e->getStatus());
        });
    }

    /**
     * @param Call $call
     * @param array $options
     * @param string $status
     *
     * @return PromiseInterface
     * @throws ApiException
     */
    private function retry(Call $call, array $options, string $status)
    {
        $delayMult = $this->retrySettings->getRetryDelayMultiplier();
        $maxDelayMs = $this->retrySettings->getMaxRetryDelayMillis();
        $timeoutMult = $this->retrySettings->getRpcTimeoutMultiplier();
        $maxTimeoutMs = $this->retrySettings->getMaxRpcTimeoutMillis();
        $totalTimeoutMs = $this->retrySettings->getTotalTimeoutMillis();

        $delayMs = $this->retrySettings->getInitialRetryDelayMillis();
        $timeoutMs = $options['timeoutMillis'];
        $currentTimeMs = $this->getCurrentTimeMs();
        $deadlineMs = $this->deadlineMs ?: $currentTimeMs + $totalTimeoutMs;

        if ($currentTimeMs >= $deadlineMs) {
            throw new ApiException(
                'Retry total timeout exceeded.',
                \Google\Rpc\Code::DEADLINE_EXCEEDED,
                ApiStatus::DEADLINE_EXCEEDED
            );
        }

        $delayMs = min($delayMs * $delayMult, $maxDelayMs);
        $timeoutMs = (int) min(
            $timeoutMs * $timeoutMult,
            $maxTimeoutMs,
            $deadlineMs - $this->getCurrentTimeMs()
        );

        $nextHandler = new RetryMiddleware(
            $this->nextHandler,
            $this->retrySettings->with([
                'initialRetryDelayMillis' => $delayMs,
            ]),
            $deadlineMs,
            $this->retryAttempts + 1
        );

        
        $options['timeoutMillis'] = $timeoutMs;

        return $nextHandler(
            $call,
            $options
        );
    }

    protected function getCurrentTimeMs()
    {
        return microtime(true) * 1000.0;
    }

    /**
     * This is the default retry behaviour.
     */
    private function getRetryFunction()
    {
        return $this->retrySettings->getRetryFunction() ??
            function (\Throwable $e, array $options): bool {
                
                
                
                if (!$e instanceof ApiException) {
                    return false;
                }

                if (!in_array($e->getStatus(), $this->retrySettings->getRetryableCodes())) {
                    return false;
                }

                return true;
            };
    }
}
