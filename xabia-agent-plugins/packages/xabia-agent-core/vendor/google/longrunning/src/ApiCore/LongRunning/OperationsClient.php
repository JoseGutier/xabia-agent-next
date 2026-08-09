<?php

namespace Google\ApiCore\LongRunning;

use Google\ApiCore\LongRunning\Gapic\OperationsGapicClient;

if (false) {
    /**
     * This class is deprecated. Use Google\LongRunning\OperationsClient instead.
     * @deprecated
     */
    class OperationsClient extends OperationsGapicClient {}
}

class_exists('\Google\LongRunning\OperationsClient');
