<?php

namespace Google\ApiCore;

use Google\ApiCore\ResourceTemplate\AbsoluteResourceTemplate;
use Google\ApiCore\ResourceTemplate\RelativeResourceTemplate;
use Google\ApiCore\ResourceTemplate\ResourceTemplateInterface;

/**
 * Represents a path template.
 *
 * Templates use the syntax of the API platform; see the protobuf of HttpRule for
 * details. A template consists of a sequence of literals, wildcards, and variable bindings,
 * where each binding can have a sub-path. A string representation can be parsed into an
 * instance of PathTemplate, which can then be used to perform matching and instantiation.
 */
class PathTemplate implements ResourceTemplateInterface
{
    private $resourceTemplate;

    /**
     * PathTemplate constructor.
     *
     * @param string $path A path template string
     * @throws ValidationException When $path cannot be parsed into a valid PathTemplate
     */
    public function __construct(?string $path = null)
    {
        if (empty($path)) {
            throw new ValidationException('Cannot construct PathTemplate from empty string');
        }

        if ($path[0] === '/') {
            $this->resourceTemplate = new AbsoluteResourceTemplate($path);
        } else {
            $this->resourceTemplate = new RelativeResourceTemplate($path);
        }
    }

    /**
     * @return string A string representation of the path template
     */
    public function __toString()
    {
        return $this->resourceTemplate->__toString();
    }

    /**
     * Renders a path template using the provided bindings.
     *
     * @param array $bindings An array matching var names to binding strings.
     * @throws ValidationException if a key isn't provided or if a sub-template
     *    can't be parsed.
     * @return string A rendered representation of this path template.
     */
    public function render(array $bindings)
    {
        return $this->resourceTemplate->render($bindings);
    }

    /**
     * Check if $path matches a resource string.
     *
     * @param string $path A resource string.
     * @return bool
     */
    public function matches(string $path)
    {
        return $this->resourceTemplate->matches($path);
    }

    /**
     * Matches a fully qualified path template string.
     *
     * @param string $path A fully qualified path template string.
     * @throws ValidationException if path can't be matched to the template.
     * @return array Array matching var names to binding values.
     */
    public function match(string $path)
    {
        return $this->resourceTemplate->match($path);
    }
}
