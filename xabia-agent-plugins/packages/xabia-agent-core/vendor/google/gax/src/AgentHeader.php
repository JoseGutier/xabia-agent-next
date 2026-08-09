<?php

namespace Google\ApiCore;

/**
 * Class containing functions used to build the Agent header.
 */
class AgentHeader
{
    const AGENT_HEADER_KEY = 'x-goog-api-client';
    const UNKNOWN_VERSION = '';

    /**
     * @param array $headerInfo {
     *     Optional.
     *
     *     @type string $phpVersion the PHP version.
     *     @type string $libName the name of the client application.
     *     @type string $libVersion the version of the client application.
     *     @type string $gapicVersion the code generator version of the GAPIC library.
     *     @type string $apiCoreVersion the ApiCore version.
     *     @type string $grpcVersion the gRPC version.
     *     @type string $restVersion the REST transport version (typically same as the
     *           ApiCore version).
     *     @type string $protobufVersion the protobuf version in format 'x.y.z+a' where both 'x.y.z'
     *           and '+a' are optional, and where 'a' is a single letter representing the
     *           implementation type of the protobuf runtime. It is recommended to use 'c' for a C
     *           implementation, and 'n' for the native language implementation (PHP).
     * }
     * @return array Agent header array
     */
    public static function buildAgentHeader(array $headerInfo)
    {
        $metricsHeaders = [];

        
        
        
        
        
        
        
        
        

        $metricsHeaders['gl-php'] = $headerInfo['phpVersion'] ?? phpversion();

        if (isset($headerInfo['libName'])) {
            $metricsHeaders[$headerInfo['libName']] =
                $headerInfo['libVersion'] ?? self::UNKNOWN_VERSION;
        }

        $apiCoreVersion = $headerInfo['apiCoreVersion'] ?? Version::getApiCoreVersion();
        $metricsHeaders['gapic'] = $headerInfo['gapicVersion'] ?? self::UNKNOWN_VERSION;
        $metricsHeaders['gax'] = $apiCoreVersion;

        
        
        
        
        
        
        
        $metricsHeaders['grpc'] = $headerInfo['grpcVersion'] ?? phpversion('grpc');
        $metricsHeaders['rest'] = $headerInfo['restVersion'] ?? $apiCoreVersion;

        
        
        $metricsHeaders['pb'] = $headerInfo['protobufVersion']
            ?? (phpversion('protobuf') ? phpversion('protobuf') . '+c' : '+n');

        $metricsList = [];
        foreach ($metricsHeaders as $key => $value) {
            $metricsList[] = $key . '/' . $value;
        }
        return [self::AGENT_HEADER_KEY => [implode(' ', $metricsList)]];
    }

    /**
     * Reads the gapic version string from a VERSION file. In order to determine the file
     * location, this method follows this procedure:
     * - accepts a class name $callingClass
     * - identifies the file defining that class
     * - searches up the directory structure for the 'src' directory
     * - looks in the directory above 'src' for a file named VERSION
     *
     * @param string $callingClass
     * @return string the gapic version
     * @throws \ReflectionException
     */
    public static function readGapicVersionFromFile(string $callingClass)
    {
        $callingClassFile = (new \ReflectionClass($callingClass))->getFileName();
        $versionFile = substr(
            $callingClassFile,
            0,
            strrpos($callingClassFile, DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR)
        ) . DIRECTORY_SEPARATOR . 'VERSION';

        return Version::readVersionFile($versionFile);
    }
}
