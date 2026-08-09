<?php

namespace Google\ApiCore;

use Google\ApiCore\Options\ClientOptions;
use Google\Auth\ApplicationDefaultCredentials;
use Google\Auth\CredentialsLoader;
use Google\Auth\FetchAuthTokenInterface;
use Google\Auth\GetUniverseDomainInterface;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use Grpc\Gcp\ApiConfig;
use Grpc\Gcp\Config;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Common functions used to work with various clients.
 *
 * @internal
 */
trait ClientOptionsTrait
{
    use ArrayTrait;

    private static $gapicVersionFromFile;

    private static function getGapicVersion(array $options)
    {
        if (isset($options['libVersion'])) {
            return $options['libVersion'];
        }
        if (!isset(self::$gapicVersionFromFile)) {
            self::$gapicVersionFromFile = AgentHeader::readGapicVersionFromFile(__CLASS__);
        }
        return self::$gapicVersionFromFile;
    }

    private static function initGrpcGcpConfig(string $hostName, string $confPath)
    {
        $apiConfig = new ApiConfig();
        $apiConfig->mergeFromJsonString(file_get_contents($confPath));
        $config = new Config($hostName, $apiConfig);
        return $config;
    }

    /**
     * Get default options. This function should be "overridden" by clients using late static
     * binding to provide default options to the client.
     *
     * @return array
     * @access private
     */
    private static function getClientDefaults()
    {
        return [];
    }

    /**
     * Resolve client options based on the client's default
     * ({@see ClientOptionsTrait::getClientDefault}) and the default for all
     * Google APIs.
     *
     * 1. Set default client option values
     * 2. Set default logger (and log user-supplied configuration options)
     * 3. Set default transport configuration
     * 4. Call "modifyClientOptions" (for backwards compatibility)
     * 5. Use "defaultScopes" when custom endpoint is supplied
     * 6. Load mTLS from the environment if configured
     * 7. Resolve endpoint based on universe domain template when possible
     * 8. Load sysvshm grpc config when possible
     */
    private function buildClientOptions(array|ClientOptions $options)
    {
        if ($options instanceof ClientOptions) {
            $options = $options->toArray();
        }

        
        
        
        $defaultOptions = self::getClientDefaults();
        $defaultOptions += [
            'disableRetries' => false,
            'credentials' => null,
            'credentialsConfig' => [],
            'transport' => null,
            'transportConfig' => [],
            'gapicVersion' => self::getGapicVersion($options),
            'libName' => null,
            'libVersion' => null,
            'apiEndpoint' => null,
            'clientCertSource' => null,
            'universeDomain' => null,
            'logger' => null,
        ];

        $supportedTransports = $this->supportedTransports();
        foreach ($supportedTransports as $transportName) {
            if (!array_key_exists($transportName, $defaultOptions['transportConfig'])) {
                $defaultOptions['transportConfig'][$transportName] = [];
            }
        }
        if (in_array('grpc', $supportedTransports)) {
            $defaultOptions['transportConfig']['grpc'] = [
                'stubOpts' => ['grpc.service_config_disable_resolution' => 1]
            ];
        }

        
        $apiEndpoint = $options['apiEndpoint'] ?? null;

        
        $clientSuppliedOptions = $options;

        
        
        
        $options += $defaultOptions;

        
        if (is_null($options['logger'])) {
            $options['logger'] = ApplicationDefaultCredentials::getDefaultLogger();
        }

        if (
            $options['logger'] !== null
            && $options['logger'] !== false
            && !$options['logger'] instanceof LoggerInterface
        ) {
            throw new ValidationException(
                'The "logger" option in the options array should be PSR-3 LoggerInterface compatible'
            );
        }

        
        $this->logConfiguration($options['logger'], $clientSuppliedOptions);

        if (isset($options['logger'])) {
            $options['credentialsConfig']['authHttpHandler'] = HttpHandlerFactory::build(
                logger: $options['logger']
            );
        }

        $options['credentialsConfig'] += $defaultOptions['credentialsConfig'];
        $options['transportConfig'] += $defaultOptions['transportConfig'];  
        if (isset($options['transportConfig']['grpc'])) {
            $options['transportConfig']['grpc'] += $defaultOptions['transportConfig']['grpc'];
            $options['transportConfig']['grpc']['stubOpts'] += $defaultOptions['transportConfig']['grpc']['stubOpts'];
            $options['transportConfig']['grpc']['logger'] = $options['logger'] ?? null;
        }
        if (isset($options['transportConfig']['rest'])) {
            $options['transportConfig']['rest'] += $defaultOptions['transportConfig']['rest'];
            $options['transportConfig']['rest']['logger'] = $options['logger'] ?? null;
        }
        if (isset($options['transportConfig']['grpc-fallback'])) {
            $options['transportConfig']['grpc-fallback']['logger'] = $options['logger'] ?? null;
        }

        
        if ($this->isBackwardsCompatibilityMode()) {
            $preModifiedOptions = $options;
            $this->modifyClientOptions($options);
            
            if ($options['apiEndpoint'] !== $preModifiedOptions['apiEndpoint']) {
                $apiEndpoint = $options['apiEndpoint'];
            }

            
            if (isset($options['serviceAddress'])) {
                $apiEndpoint = $this->pluck('serviceAddress', $options, false);
            }
        } else {
            
            
            
            $this->modifyClientOptions($options);
        }
        
        
        if ($apiEndpoint
            && $apiEndpoint != $defaultOptions['apiEndpoint']
            && empty($options['credentialsConfig']['scopes'])
            && !empty($options['credentialsConfig']['defaultScopes'])
        ) {
            $options['credentialsConfig']['scopes'] = $options['credentialsConfig']['defaultScopes'];
        }

        
        
        if (empty($options['clientCertSource']) && CredentialsLoader::shouldLoadClientCertSource()) {
            if ($defaultCertSource = CredentialsLoader::getDefaultClientCertSource()) {
                $options['clientCertSource'] = function () use ($defaultCertSource) {
                    $cert = call_user_func($defaultCertSource);

                    
                    return [$cert, $cert];
                };
            }
        }

        
        
        if (is_null($apiEndpoint) && $this->shouldUseMtlsEndpoint($options)) {
            $apiEndpoint = self::determineMtlsEndpoint($options['apiEndpoint']);
        }

        
        
        $options['universeDomain'] ??= getenv('GOOGLE_CLOUD_UNIVERSE_DOMAIN')
            ?: GetUniverseDomainInterface::DEFAULT_UNIVERSE_DOMAIN;

        
        if (isset($options['clientCertSource'])
            && $options['universeDomain'] !== GetUniverseDomainInterface::DEFAULT_UNIVERSE_DOMAIN
        ) {
            throw new ValidationException(
                'mTLS is not supported outside the "googleapis.com" universe'
            );
        }

        if (is_null($apiEndpoint)) {
            if (defined('self::SERVICE_ADDRESS_TEMPLATE')) {
                
                $apiEndpoint = str_replace(
                    'UNIVERSE_DOMAIN',
                    $options['universeDomain'],
                    self::SERVICE_ADDRESS_TEMPLATE
                );
            } else {
                
                
                $apiEndpoint = $defaultOptions['apiEndpoint'];
            }
        }

        if (extension_loaded('sysvshm')
            && isset($options['gcpApiConfigPath'])
            && file_exists($options['gcpApiConfigPath'])
            && !empty($apiEndpoint)
        ) {
            $grpcGcpConfig = self::initGrpcGcpConfig(
                $apiEndpoint,
                $options['gcpApiConfigPath']
            );

            if (!array_key_exists('stubOpts', $options['transportConfig']['grpc'])) {
                $options['transportConfig']['grpc']['stubOpts'] = [];
            }

            $options['transportConfig']['grpc']['stubOpts'] += [
                'grpc_call_invoker' => $grpcGcpConfig->callInvoker()
            ];
        }

        $options['apiEndpoint'] = $apiEndpoint;

        return $options;
    }

    private function shouldUseMtlsEndpoint(array $options)
    {
        $mtlsEndpointEnvVar = getenv('GOOGLE_API_USE_MTLS_ENDPOINT');
        if ('always' === $mtlsEndpointEnvVar) {
            return true;
        }
        if ('never' === $mtlsEndpointEnvVar) {
            return false;
        }
        
        return !empty($options['clientCertSource']);
    }

    private static function determineMtlsEndpoint(string $apiEndpoint)
    {
        $parts = explode('.', $apiEndpoint);
        if (count($parts) < 3) {
            return $apiEndpoint; 
        }
        return sprintf('%s.mtls.%s', array_shift($parts), implode('.', $parts));
    }

    /**
     * @param mixed $credentials
     * @param array $credentialsConfig
     * @return CredentialsWrapper
     * @throws ValidationException
     */
    private function createCredentialsWrapper($credentials, array $credentialsConfig, string $universeDomain)
    {
        if (is_null($credentials)) {
            
            return CredentialsWrapper::build($credentialsConfig, $universeDomain);
        }

        if (is_string($credentials) || is_array($credentials)) {
            return CredentialsWrapper::build(['keyFile' => $credentials] + $credentialsConfig, $universeDomain);
        }

        if ($credentials instanceof FetchAuthTokenInterface) {
            $authHttpHandler = $credentialsConfig['authHttpHandler'] ?? null;
            return new CredentialsWrapper($credentials, $authHttpHandler, $universeDomain);
        }

        if ($credentials instanceof CredentialsWrapper) {
            return $credentials;
        }

        throw new ValidationException(sprintf(
            'Unexpected value in $auth option, got: %s',
            print_r($credentials, true)
        ));
    }

    /**
     * This defaults to all three transports, which One-Platform supports.
     * Discovery clients should define this function and only return ['rest'].
     */
    private static function supportedTransports()
    {
        return ['grpc', 'grpc-fallback', 'rest'];
    }

    
    
    
    

    /**
     * Modify options passed to the client before calling setClientOptions.
     *
     * @param array $options
     * @access private
     * @internal
     */
    protected function modifyClientOptions(array &$options)
    {
        
    }

    /**
     * @internal
     */
    private function isBackwardsCompatibilityMode(): bool
    {
        return false;
    }

    /**
     * @param null|false|LoggerInterface $logger
     * @param string $options
     */
    private function logConfiguration(null|false|LoggerInterface $logger, array $options): void
    {
        if (!$logger) {
            return;
        }

        $configurationLog = [
            'timestamp' => date(DATE_RFC3339),
            'severity' => strtoupper(LogLevel::DEBUG),
            'processId' => getmypid(),
            'jsonPayload' => [
                'serviceName' => self::SERVICE_NAME, 
                'clientConfiguration' => $options,
            ]
        ];

        $logger->debug(json_encode($configurationLog));
    }
}
