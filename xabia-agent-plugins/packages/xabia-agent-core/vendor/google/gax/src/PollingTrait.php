<?php

namespace Google\ApiCore;

/**
 * @internal
 */
trait PollingTrait
{
    /**
     * @param callable $pollCallable The call to poll. Must return a boolean indicating whether the
     *        operation has completed, and the polling loop can be terminated.
     * @param array $options {
     *                       Options for configuring the polling behaviour.
     *
     *     @type int $initialPollDelayMillis The initial polling interval to use, in milliseconds.
     *     @type int $pollDelayMultiplier Multiplier applied to the polling interval on each retry.
     *     @type int $maxPollDelayMillis The maximum polling interval to use, in milliseconds.
     *     @type int $totalPollTimeoutMillis The maximum amount of time to continue polling, in milliseconds.
     * }
     * @return bool
     */
    private function poll(callable $pollCallable, array $options)
    {
        $currentPollDelayMillis = $options['initialPollDelayMillis'];
        $pollDelayMultiplier = $options['pollDelayMultiplier'];
        $maxPollDelayMillis = $options['maxPollDelayMillis'];
        $totalPollTimeoutMillis = $options['totalPollTimeoutMillis'];

        $hasTotalPollTimeout = $totalPollTimeoutMillis > 0.0;
        $endTime = $this->getCurrentTimeMillis() + $totalPollTimeoutMillis;

        while (true) {
            if ($hasTotalPollTimeout && $this->getCurrentTimeMillis() > $endTime) {
                return false;
            }
            $this->sleepMillis($currentPollDelayMillis);
            if ($pollCallable()) {
                return true;
            }
            $currentPollDelayMillis = (int) min([
                $currentPollDelayMillis * $pollDelayMultiplier,
                $maxPollDelayMillis
            ]);
        }
    }

    /**
     * Protected to allow overriding for tests
     *
     * @return float Current time in milliseconds
     */
    protected function getCurrentTimeMillis()
    {
        return microtime(true) * 1000.0;
    }

    /**
     * Protected to allow overriding for tests
     *
     * @param int $millis
     */
    protected function sleepMillis(int $millis)
    {
        usleep($millis * 1000);
    }
}
