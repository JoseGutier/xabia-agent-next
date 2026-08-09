<?php

namespace Google\ApiCore\ResourceTemplate;

use Google\ApiCore\ValidationException;

/**
 * Represents a relative resource template, meaning that it will never contain a leading slash or
 * trailing verb (":<verb>").
 *
 * Examples:
 *   projects
 *   projects/{project}
 *   foo/{bar=**}/fizz/*
 *
 * Templates use the syntax of the API platform; see
 * https://github.com/googleapis/api-common-protos/blob/master/google/api/http.proto
 * for details. A template consists of a sequence of literals, wildcards, and variable bindings,
 * where each binding can have a sub-path. A string representation can be parsed into an
 * instance of AbsoluteResourceTemplate, which can then be used to perform matching and instantiation.
 *
 * @internal
 */
class RelativeResourceTemplate implements ResourceTemplateInterface
{
    /** @var Segment[] */
    private array $segments;

    /**
     * RelativeResourceTemplate constructor.
     *
     * @param string $path
     * @throws ValidationException
     */
    public function __construct(string $path)
    {
        if (empty($path)) {
            throw new ValidationException('Cannot construct RelativeResourceTemplate from empty string');
        }
        $this->segments = Parser::parseSegments($path);

        $doubleWildcardCount = self::countDoubleWildcards($this->segments);
        if ($doubleWildcardCount > 1) {
            throw new ValidationException(
                "Cannot parse '$path': cannot contain more than one path wildcard"
            );
        }

        
        $keys = [];
        foreach ($this->segments as $segment) {
            if ($segment->getSegmentType() === Segment::VARIABLE_SEGMENT) {
                if (isset($keys[$segment->getKey()])) {
                    throw new ValidationException(
                        "Duplicate key '{$segment->getKey()}' in path $path"
                    );
                }
                $keys[$segment->getKey()] = true;
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function __toString()
    {
        return self::renderSegments($this->segments);
    }

    /**
     * @inheritdoc
     */
    public function render(array $bindings)
    {
        $literalSegments = [];
        $keySegmentTuples = self::buildKeySegmentTuples($this->segments);
        foreach ($keySegmentTuples as list($key, $segment)) {
            /** @var Segment $segment */
            if ($segment->getSegmentType() === Segment::LITERAL_SEGMENT) {
                $literalSegments[] = $segment;
                continue;
            }
            if (!array_key_exists($key, $bindings)) {
                throw $this->renderingException($bindings, "missing required binding '$key' for segment '$segment'");
            }
            $value = $bindings[$key];
            if (!is_null($value) && $segment->matches($value)) {
                $literalSegments[] = new Segment(
                    Segment::LITERAL_SEGMENT,
                    $value,
                    $segment->getValue(),
                    $segment->getTemplate(),
                    $segment->getSeparator()
                );
            } else {
                $valueString = is_null($value) ? 'null' : "'$value'";
                throw $this->renderingException(
                    $bindings,
                    "expected binding '$key' to match segment '$segment', instead got $valueString"
                );
            }
        }
        return self::renderSegments($literalSegments);
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
        
        
        
        
        
        

        
        
        
        
        
        $keySegmentTuples = self::buildKeySegmentTuples($this->segments);

        $flattenedKeySegmentTuples = self::flattenKeySegmentTuples($keySegmentTuples);
        $flattenedKeySegmentTuplesCount = count($flattenedKeySegmentTuples);
        assert($flattenedKeySegmentTuplesCount > 0);

        $slashPathPieces = explode('/', $path);
        $pathPieces = [];
        $pathPiecesIndex = 0;
        $startIndex = 0;
        $slashPathPiecesCount = count($slashPathPieces);
        $doubleWildcardPieceCount = $slashPathPiecesCount - $flattenedKeySegmentTuplesCount + 1;

        for ($i = 0; $i < count($flattenedKeySegmentTuples); $i++) {
            $segmentKey = $flattenedKeySegmentTuples[$i][0];
            $segment = $flattenedKeySegmentTuples[$i][1];
            
            assert($segment->getSegmentType() !== Segment::VARIABLE_SEGMENT);

            if ($segment->getSegmentType() == Segment::DOUBLE_WILDCARD_SEGMENT) {
                $pathPiecesForSegment = array_slice($slashPathPieces, $pathPiecesIndex, $doubleWildcardPieceCount);
                $pathPiece = implode('/', $pathPiecesForSegment);
                $pathPiecesIndex += $doubleWildcardPieceCount;
                $pathPieces[] = $pathPiece;
                continue;
            }

            if ($segment->getSegmentType() == Segment::WILDCARD_SEGMENT) {
                if ($pathPiecesIndex >= $slashPathPiecesCount) {
                    break;
                }
            }
            if ($segment->getSeparator() === '/') {
                if ($pathPiecesIndex >= $slashPathPiecesCount) {
                    throw $this->matchException($path, 'segment and path length mismatch');
                }
                $pathPiece = substr($slashPathPieces[$pathPiecesIndex++], $startIndex);
                $startIndex = 0;
            } else {
                $rawPiece = substr($slashPathPieces[$pathPiecesIndex], $startIndex);
                $pathPieceLength = strpos($rawPiece, $segment->getSeparator());
                $pathPiece = substr($rawPiece, 0, $pathPieceLength);
                $startIndex += $pathPieceLength + 1;
            }
            $pathPieces[] = $pathPiece;
        }

        if ($flattenedKeySegmentTuples[$i - 1][1]->getSegmentType() !== Segment::DOUBLE_WILDCARD_SEGMENT) {
            
            for (; $pathPiecesIndex < count($slashPathPieces); $pathPiecesIndex++) {
                $pathPieces[] = $slashPathPieces[$pathPiecesIndex];
            }
        }
        $pathPiecesCount = count($pathPieces);

        
        
        
        
        
        

        if ($pathPiecesCount < $flattenedKeySegmentTuplesCount) {
            
            
            throw $this->matchException($path, 'path does not contain enough segments to be matched');
        }

        $doubleWildcardPieceCount = $pathPiecesCount - $flattenedKeySegmentTuplesCount + 1;

        $bindings = [];
        $pathPiecesIndex = 0;
        /** @var Segment $segment */
        foreach ($flattenedKeySegmentTuples as list($segmentKey, $segment)) {
            $pathPiece = $pathPieces[$pathPiecesIndex++];
            if (!$segment->matches($pathPiece)) {
                throw $this->matchException($path, "expected path element matching '$segment', got '$pathPiece'");
            }

            
            
            
            
            if (isset($segmentKey)) {
                $bindings += [$segmentKey => []];
                $bindings[$segmentKey][] = $pathPiece;
            }
        }

        
        
        if ($pathPiecesIndex !== $pathPiecesCount) {
            throw $this->matchException($path, "expected end of path, got '$pathPieces[$pathPiecesIndex]'");
        }

        
        $collapsedBindings = [];
        foreach ($bindings as $key => $boundPieces) {
            $collapsedBindings[$key] = implode('/', $boundPieces);
        }

        return $collapsedBindings;
    }

    private function matchException(string $path, string $reason)
    {
        return new ValidationException("Could not match path '$path' to template '$this': $reason");
    }

    private function renderingException(array $bindings, string $reason)
    {
        $bindingsString = print_r($bindings, true);
        return new ValidationException(
            "Error rendering '$this': $reason\n" .
            "Provided bindings: $bindingsString"
        );
    }

    /**
     * @param Segment[] $segments
     * @param string|null $separator An optional string separator
     * @return array[] A list of [string, Segment] tuples
     */
    private static function buildKeySegmentTuples(array $segments, ?string $separator = null)
    {
        $keySegmentTuples = [];
        $positionalArgumentCounter = 0;
        foreach ($segments as $segment) {
            switch ($segment->getSegmentType()) {
                case Segment::WILDCARD_SEGMENT:
                case Segment::DOUBLE_WILDCARD_SEGMENT:
                    $positionalKey = "\$$positionalArgumentCounter";
                    $positionalArgumentCounter++;
                    $newSegment = $segment;
                    if ($separator !== null) {
                        $newSegment = new Segment(
                            $segment->getSegmentType(),
                            $segment->getValue(),
                            $segment->getKey(),
                            $segment->getTemplate(),
                            $separator
                        );
                    }
                    $keySegmentTuples[] = [$positionalKey, $newSegment];
                    break;
                default:
                    $keySegmentTuples[] = [$segment->getKey(), $segment];
            }
        }
        return $keySegmentTuples;
    }

    /**
     * @param array[] $keySegmentTuples A list of [string, Segment] tuples
     * @return array[] A list of [string, Segment] tuples
     */
    private static function flattenKeySegmentTuples(array $keySegmentTuples)
    {
        $flattenedKeySegmentTuples = [];
        foreach ($keySegmentTuples as list($key, $segment)) {
            /** @var Segment $segment */
            switch ($segment->getSegmentType()) {
                case Segment::VARIABLE_SEGMENT:
                    
                    $template = $segment->getTemplate();
                    $nestedKeySegmentTuples = self::buildKeySegmentTuples(
                        $template->segments,
                        $segment->getSeparator()
                    );
                    foreach ($nestedKeySegmentTuples as list($nestedKey, $nestedSegment)) {
                        /** @var Segment $nestedSegment */
                        
                        assert($nestedSegment->getSegmentType() !== Segment::VARIABLE_SEGMENT);
                        
                        
                        $flattenedKeySegmentTuples[] = [$key, $nestedSegment];
                    }
                    break;
                default:
                    
                    $flattenedKeySegmentTuples[] = [$key, $segment];
            }
        }
        return $flattenedKeySegmentTuples;
    }

    /**
     * @param Segment[] $segments
     * @return int
     */
    private static function countDoubleWildcards(array $segments)
    {
        $doubleWildcardCount = 0;
        foreach ($segments as $segment) {
            switch ($segment->getSegmentType()) {
                case Segment::DOUBLE_WILDCARD_SEGMENT:
                    $doubleWildcardCount++;
                    break;
                case Segment::VARIABLE_SEGMENT:
                    $doubleWildcardCount += self::countDoubleWildcards($segment->getTemplate()->segments);
                    break;
            }
        }
        return $doubleWildcardCount;
    }

    /**
     * Joins segments using their separators.
     * @param array $segmentsToRender
     * @return string
     */
    private static function renderSegments(array $segmentsToRender)
    {
        $renderResult = '';
        for ($i = 0; $i < count($segmentsToRender); $i++) {
            $segment = $segmentsToRender[$i];
            $renderResult .= $segment;
            if ($i < count($segmentsToRender) - 1) {
                $renderResult .= $segment->getSeparator();
            }
        }
        return $renderResult;
    }
}
