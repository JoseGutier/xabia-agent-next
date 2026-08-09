<?php

namespace Google\ApiCore\ResourceTemplate;

use Google\ApiCore\ValidationException;

/**
 * Represents an absolute resource template, meaning that it will always contain a leading slash,
 * and may contain a trailing verb (":<verb>").
 *
 * Examples:
 *   /projects
 *   /projects/{project}
 *   /foo/{bar=**}/fizz/*:action
 *
 * Templates use the syntax of the API platform; see
 * https://github.com/googleapis/api-common-protos/blob/master/google/api/http.proto
 * for details. A template consists of a sequence of literals, wildcards, and variable bindings,
 * where each binding can have a sub-path. A string representation can be parsed into an
 * instance of AbsoluteResourceTemplate, which can then be used to perform matching and instantiation.
 *
 * @internal
 */
class AbsoluteResourceTemplate implements ResourceTemplateInterface
{
    private RelativeResourceTemplate $resourceTemplate;
    /** @var string|bool */
    private $verb;

    /**
     * AbsoluteResourceTemplate constructor.
     * @param string $path
     * @throws ValidationException
     */
    public function __construct(string $path)
    {
        if (empty($path)) {
            throw new ValidationException('Cannot construct AbsoluteResourceTemplate from empty string');
        }
        if ($path[0] !== '/') {
            throw new ValidationException(
                "Could not construct AbsoluteResourceTemplate from '$path': must begin with '/'"
            );
        }
        $verbSeparatorPos = $this->verbSeparatorPos($path);
        $this->resourceTemplate = new RelativeResourceTemplate(substr($path, 1, $verbSeparatorPos - 1));
        $this->verb = substr($path, $verbSeparatorPos + 1);
    }

    /**
     * @inheritdoc
     */
    public function __toString()
    {
        return sprintf('/%s%s', $this->resourceTemplate, $this->renderVerb());
    }

    /**
     * @inheritdoc
     */
    public function render(array $bindings)
    {
        return sprintf('/%s%s', $this->resourceTemplate->render($bindings), $this->renderVerb());
    }

    /**
     * @inheritdoc
     */
    public function matches(string $path)
    {
        try {
            $this->match($path);
            return true;
        } catch (ValidationException $ex) {
            return false;
        }
    }

    /**
     * @inheritdoc
     */
    public function match(string $path)
    {
        if (empty($path)) {
            throw $this->matchException($path, 'path cannot be empty');
        }
        if ($path[0] !== '/') {
            throw $this->matchException($path, "missing leading '/'");
        }
        $verbSeparatorPos = $this->verbSeparatorPos($path);
        if (substr($path, $verbSeparatorPos + 1) !== $this->verb) {
            throw $this->matchException($path, "trailing verb did not match '{$this->verb}'");
        }
        return $this->resourceTemplate->match(substr($path, 1, $verbSeparatorPos - 1));
    }

    private function matchException(string $path, string $reason)
    {
        return new ValidationException("Could not match path '$path' to template '$this': $reason");
    }

    private function renderVerb()
    {
        return $this->verb ? ':' . $this->verb : '';
    }

    private function verbSeparatorPos(string $path)
    {
        $finalSeparatorPos = strrpos($path, '/');
        $verbSeparatorPos = strrpos($path, ':', $finalSeparatorPos);
        if ($verbSeparatorPos === false) {
            $verbSeparatorPos = strlen($path);
        }
        return $verbSeparatorPos;
    }
}
