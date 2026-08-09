<?php

namespace Google\Auth\Cache;

use Psr\Cache\InvalidArgumentException as PsrInvalidArgumentException;

class InvalidArgumentException extends \InvalidArgumentException implements PsrInvalidArgumentException
{
}
