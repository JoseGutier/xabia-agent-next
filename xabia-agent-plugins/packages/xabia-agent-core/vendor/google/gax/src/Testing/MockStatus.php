<?php

namespace Google\ApiCore\Testing;

use Google\Rpc\Code;
use stdClass;

/**
 * @internal
 */
class MockStatus extends stdClass
{
    /** @var Code|int $code */
    public $code;
    public $details;
    public $metadata;
    public function __construct($code, ?string $details = null, array $metadata = [])
    {
        $this->code = $code;
        $this->details = $details;
        $this->metadata = $metadata;
    }
}
