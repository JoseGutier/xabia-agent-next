<?php

namespace Google\ApiCore;

/**
 * @internal
 */
class Version
{
    /**
     * @var ?string
     */
    private static $version = null;

    /**
     * @return string The version of the ApiCore library.
     */
    public static function getApiCoreVersion()
    {
        if (is_null(self::$version)) {
            $versionFile = implode(DIRECTORY_SEPARATOR, [__DIR__, '..', 'VERSION']);
            self::$version = self::readVersionFile($versionFile);
        }
        return self::$version;
    }

    /**
     * Reads a VERSION file and returns the contents. If the file does not
     * exist, returns "".
     *
     * @param string $file
     * @return string
     */
    public static function readVersionFile(string $file)
    {
        $versionString = file_exists($file)
            ? (string) file_get_contents($file)
            : '';
        return trim($versionString);
    }

    /**
     * Private constructor.
     */
    private function __construct()
    {
    }
}
