<?php

namespace Google\ApiCore\ResourceTemplate;

use Google\ApiCore\ValidationException;

/**
 * Collection of methods for parsing Segments.
 *
 * @internal
 */
class Parser
{
    /**
     * Parses a path into an array of segments.
     *
     * @param string|null $path
     * @return array
     * @throws ValidationException
     */
    public static function parseSegments(?string $path = null)
    {
        if (empty($path)) {
            throw new ValidationException('Cannot parse empty path');
        }
        $segments = [];
        $index = 0;
        $nextLiteral = '';
        $segments[] = self::parseSegmentFromPath($path, $nextLiteral, $index);
        while ($index < strlen($path)) {
            self::parseLiteralFromPath($nextLiteral, $path, $index);
            $segments[] = self::parseSegmentFromPath($path, $nextLiteral, $index);
        }
        return $segments;
    }

    /**
     * Given a path and an index, reads a Segment from the path and updates
     * the index.
     *
     * @param string $path
     * @param string $nextLiteral
     * @param int $index
     * @return Segment
     * @throws ValidationException
     */
    private static function parseSegmentFromPath(string $path, string &$nextLiteral, int &$index)
    {
        if ($index >= strlen($path)) {
            
            
            throw self::parseError($path, strlen($path) - 1, "invalid trailing '/'");
        }
        if ($path[$index] === '{') {
            
            $closingBraceIndex = strpos($path, '}', $index);
            if ($closingBraceIndex === false) {
                throw self::parseError(
                    $path,
                    strlen($path),
                    "Expected '}' to match '{' at index $index, got end of string"
                );
            }

            $segmentStringLengthWithoutBraces = $closingBraceIndex - $index - 1;
            $segmentStringWithoutBraces = substr($path, $index + 1, $segmentStringLengthWithoutBraces);
            $index = $closingBraceIndex + 1;

            $nextLiteral = '/';
            $remainingPath = substr($path, $index);
            if (!empty($remainingPath)) {
                
                $nextSlashIndex = strpos($remainingPath, '/', 0);
                $nonSlashSeparators = ['-', '_', '~', '.'];
                foreach ($nonSlashSeparators as $nonSlashSeparator) {
                    $nonSlashSeparatorIndex = strpos($remainingPath, $nonSlashSeparator, 0);
                    $nextOpenBraceIndex = strpos($remainingPath, '{', 0);
                    if ($nonSlashSeparatorIndex !== false && $nonSlashSeparatorIndex === $nextOpenBraceIndex - 1) {
                        $index += $nonSlashSeparatorIndex;
                        $nextLiteral = $nonSlashSeparator;
                        break;
                    }
                }
            }

            return self::parseVariableSegment($segmentStringWithoutBraces, $nextLiteral);
        } else {
            $nextSlash = strpos($path, '/', $index);
            if ($nextSlash === false) {
                $nextSlash = strlen($path);
            }
            $segmentString = substr($path, $index, $nextSlash - $index);
            $nextLiteral = '/';
            $index = $nextSlash;
            return self::parse($segmentString, $path, $index);
        }
    }

    /**
     * @param string $segmentString
     * @param string $path
     * @param int $index
     * @return Segment
     * @throws ValidationException
     */
    private static function parse(string $segmentString, string $path, int $index)
    {
        if ($segmentString === '*') {
            return new Segment(Segment::WILDCARD_SEGMENT);
        } elseif ($segmentString === '**') {
            return new Segment(Segment::DOUBLE_WILDCARD_SEGMENT);
        } else {
            if (!self::isValidLiteral($segmentString)) {
                if (empty($segmentString)) {
                    
                    throw self::parseError($path, $index, "Unexpected empty segment (consecutive '/'s are invalid)");
                } else {
                    throw self::parseError($path, $index, "Unexpected characters in literal segment $segmentString");
                }
            }
            return new Segment(Segment::LITERAL_SEGMENT, $segmentString);
        }
    }

    /**
     * @param string $segmentStringWithoutBraces
     * @param string $separatorLiteral
     * @return Segment
     * @throws ValidationException
     */
    private static function parseVariableSegment(string $segmentStringWithoutBraces, string $separatorLiteral)
    {
        
        $nestedOpenBracket = strpos($segmentStringWithoutBraces, '{');
        if ($nestedOpenBracket !== false) {
            throw new ValidationException(
                "Unexpected '{' parsing segment $segmentStringWithoutBraces at index $nestedOpenBracket"
            );
        }

        $equalsIndex = strpos($segmentStringWithoutBraces, '=');
        if ($equalsIndex === false) {
            
            $variableKey = $segmentStringWithoutBraces;
            $nestedResource = new RelativeResourceTemplate('*');
        } else {
            $variableKey = substr($segmentStringWithoutBraces, 0, $equalsIndex);
            $nestedResourceString = substr($segmentStringWithoutBraces, $equalsIndex + 1);
            $nestedResource = new RelativeResourceTemplate($nestedResourceString);
        }

        if (!self::isValidLiteral($variableKey)) {
            throw new ValidationException(
                "Unexpected characters in variable name $variableKey"
            );
        }
        return new Segment(Segment::VARIABLE_SEGMENT, null, $variableKey, $nestedResource, $separatorLiteral);
    }

    /**
     * @param string $literal
     * @param string $path
     * @param int $index
     * @return string
     * @throws ValidationException
     */
    private static function parseLiteralFromPath(string $literal, string $path, int &$index)
    {
        $literalLength = strlen($literal);
        if (strlen($path) < ($index + $literalLength)) {
            throw self::parseError($path, $index, "expected '$literal'");
        }
        $consumedLiteral = substr($path, $index, $literalLength);
        if ($consumedLiteral !== $literal) {
            throw self::parseError($path, $index, "expected '$literal'");
        }
        $index += $literalLength;
        return $consumedLiteral;
    }

    private static function parseError(string $path, int $index, string $reason)
    {
        return new ValidationException("Error parsing '$path' at index $index: $reason");
    }

    /**
     * Check if $literal is a valid segment literal. Segment literals may only contain numbers,
     * letters, and any of the following: .-~_
     *
     * @param string $literal
     * @return bool
     */
    private static function isValidLiteral(string $literal)
    {
        return preg_match('/^[0-9a-zA-Z\\.\\-~_]+$/', $literal) === 1;
    }
}
