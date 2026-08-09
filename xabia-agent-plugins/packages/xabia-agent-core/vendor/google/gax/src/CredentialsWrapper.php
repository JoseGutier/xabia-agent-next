<?php

namespace Google\ApiCore;

use DomainException;
use Exception;
use Google\Auth\ApplicationDefaultCredentials;
use Google\Auth\Cache\MemoryCacheItemPool;
use Google\Auth\Credentials\GCECredentials;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Auth\CredentialsLoader;
use Google\Auth\FetchAuthTokenCache;
use Google\Auth\FetchAuthTokenInterface;
use Google\Auth\GetQuotaProjectInterface;
use Google\Auth\GetUniverseDomainInterface;
use Google\Auth\ProjectIdProviderInterface;
use Google\Auth\UpdateMetadataInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * The CredentialsWrapper object provides a wrapper around a FetchAuthTokenInterface.
 */
class CredentialsWrapper implements HeaderCredentialsInterface, ProjectIdProviderInterface
{
    use ValidationTrait;

    /** @var FetchAuthTokenInterface $credentialsFetcher */
    private ?FetchAuthTokenInterface $credentialsFetcher = null;
    /** @var callable $authHttpHandle */
    private $authHttpHandler;

    private string $universeDomain;
    private bool $hasCheckedUniverse = false;

    /** @var int */
    private static int $eagerRefreshThresholdSeconds = 10;

    /**
     * CredentialsWrapper constructor.
     * @param FetchAuthTokenInterface $credentialsFetcher A credentials loader
     *        used to fetch access tokens.
     * @param callable $authHttpHandler A handler used to deliver PSR-7 requests
     *        specifically for authentication. Should match a signature of
     *        `function (RequestInterface $request, array $options) : ResponseInterface`.
     * @throws ValidationException
     */
    public function __construct(
        FetchAuthTokenInterface $credentialsFetcher,
        ?callable $authHttpHandler = null,
        string $universeDomain = GetUniverseDomainInterface::DEFAULT_UNIVERSE_DOMAIN
    ) {
        $this->credentialsFetcher = $credentialsFetcher;
        $this->authHttpHandler = $authHttpHandler;
        if (empty($universeDomain)) {
            throw new ValidationException('The universe domain cannot be empty');
        }
        $this->universeDomain = $universeDomain;
    }

    /**
     * Factory method to create a CredentialsWrapper from an array of options.
     *
     * @param array $args {
     *     An array of optional arguments.
     *
     *     @type string|array $keyFile
     *           Credentials to be used. Accepts either a path to a credentials file, or a decoded
     *           credentials file as a PHP array. If this is not specified, application default
     *           credentials will be used.
     *     @type string[] $scopes
     *           A string array of scopes to use when acquiring credentials.
     *     @type callable $authHttpHandler
     *           A handler used to deliver PSR-7 requests specifically
     *           for authentication. Should match a signature of
     *           `function (RequestInterface $request, array $options) : ResponseInterface`.
     *     @type bool $enableCaching
     *           Enable caching of access tokens. Defaults to true.
     *     @type CacheItemPoolInterface $authCache
     *           A cache for storing access tokens. Defaults to a simple in memory implementation.
     *     @type array $authCacheOptions
     *           Cache configuration options.
     *     @type string $quotaProject
     *           Specifies a user project to bill for access charges associated with the request.
     *     @type string[] $defaultScopes
     *           A string array of default scopes to use when acquiring
     *           credentials.
     *     @type bool $useJwtAccessWithScope
     *           Ensures service account credentials use JWT Access (also known as self-signed
     *           JWTs), even when user-defined scopes are supplied.
     * }
     * @param string $universeDomain The expected universe of the credentials. Defaults to
     *                               "googleapis.com"
     * @return CredentialsWrapper
     * @throws ValidationException
     */
    public static function build(
        array $args = [],
        string $universeDomain = GetUniverseDomainInterface::DEFAULT_UNIVERSE_DOMAIN
    ) {
        $args += [
            'keyFile'           => null,
            'scopes'            => null,
            'authHttpHandler'   => null,
            'enableCaching'     => true,
            'authCache'         => null,
            'authCacheOptions'  => [],
            'quotaProject'      => null,
            'defaultScopes'     => null,
            'useJwtAccessWithScope' => true,
        ];

        $keyFile = $args['keyFile'];

        if (is_null($keyFile)) {
            $loader = self::buildApplicationDefaultCredentials(
                $args['scopes'],
                $args['authHttpHandler'],
                $args['authCacheOptions'],
                $args['authCache'],
                $args['quotaProject'],
                $args['defaultScopes']
            );
            if ($loader instanceof FetchAuthTokenCache) {
                $loader = $loader->getFetcher();
            }
        } else {
            if (is_string($keyFile)) {
                if (!file_exists($keyFile)) {
                    throw new ValidationException("Could not find keyfile: $keyFile");
                }
                $keyFile = json_decode(file_get_contents($keyFile), true);
            }

            if (isset($args['quotaProject'])) {
                $keyFile['quota_project_id'] = $args['quotaProject'];
            }

            $loader = CredentialsLoader::makeCredentials(
                $args['scopes'],
                $keyFile,
                $args['defaultScopes']
            );
        }

        if ($loader instanceof ServiceAccountCredentials && $args['useJwtAccessWithScope']) {
            
            
            $loader->useJwtAccessWithScope();
        }

        if ($args['enableCaching']) {
            $authCache = $args['authCache'] ?: new MemoryCacheItemPool();
            $loader = new FetchAuthTokenCache(
                $loader,
                $args['authCacheOptions'],
                $authCache
            );
        }

        return new CredentialsWrapper($loader, $args['authHttpHandler'], $universeDomain);
    }

    /**
     * @return string|null The quota project associated with the credentials.
     */
    public function getQuotaProject(): ?string
    {
        if ($this->credentialsFetcher instanceof GetQuotaProjectInterface) {
            return $this->credentialsFetcher->getQuotaProject();
        }
        return null;
    }

    public function getProjectId(?callable $httpHandler = null): ?string
    {
        
        if ($this->credentialsFetcher instanceof FetchAuthTokenCache
            && !$this->credentialsFetcher->getFetcher() instanceof ProjectIdProviderInterface) {
            return null;
        }

        if ($this->credentialsFetcher instanceof ProjectIdProviderInterface) {
            return $this->credentialsFetcher->getProjectId($httpHandler);
        }
        return null;
    }

    /**
     * @deprecated
     * @return string Bearer string containing access token.
     */
    public function getBearerString()
    {
        $token = $this->credentialsFetcher->getLastReceivedToken();
        if (self::isExpired($token)) {
            $this->checkUniverseDomain();

            $token = $this->credentialsFetcher->fetchAuthToken($this->authHttpHandler);
            if (!self::isValid($token)) {
                return '';
            }
        }
        return empty($token['access_token']) ? '' : 'Bearer ' . $token['access_token'];
    }

    /**
     * @param string $audience optional audience for self-signed JWTs.
     * @return callable Callable function that returns an authorization header.
     */
    public function getAuthorizationHeaderCallback($audience = null): ?callable
    {
        
        
        
        return function () use ($audience) {
            $token = $this->credentialsFetcher->getLastReceivedToken();
            if (self::isExpired($token)) {
                $this->checkUniverseDomain();

                
                if ($this->credentialsFetcher instanceof UpdateMetadataInterface) {
                    return $this->credentialsFetcher->updateMetadata([], $audience, $this->authHttpHandler);
                }

                
                
                $token = $this->credentialsFetcher->fetchAuthToken($this->authHttpHandler);
                if (!self::isValid($token)) {
                    return [];
                }
            }
            $tokenString = $token['access_token'];
            if (!empty($tokenString)) {
                return ['authorization' => ["Bearer $tokenString"]];
            }
            return [];
        };
    }

    /**
     * Verify that the expected universe domain matches the universe domain from the credentials.
     *
     * @throws ValidationException if the universe domain does not match.
     */
    public function checkUniverseDomain(): void
    {
        if (false === $this->hasCheckedUniverse && $this->shouldCheckUniverseDomain()) {
            $credentialsUniverse = $this->credentialsFetcher instanceof GetUniverseDomainInterface
                ? $this->credentialsFetcher->getUniverseDomain()
                : GetUniverseDomainInterface::DEFAULT_UNIVERSE_DOMAIN;
            if ($credentialsUniverse !== $this->universeDomain) {
                throw new ValidationException(sprintf(
                    'The configured universe domain (%s) does not match the credential universe domain (%s)',
                    $this->universeDomain,
                    $credentialsUniverse
                ));
            }
            $this->hasCheckedUniverse = true;
        }
    }

    /**
     * Skip universe domain check for Metadata server (e.g. GCE) credentials.
     *
     * @return bool
     */
    private function shouldCheckUniverseDomain(): bool
    {
        $fetcher = $this->credentialsFetcher instanceof FetchAuthTokenCache
            ? $this->credentialsFetcher->getFetcher()
            : $this->credentialsFetcher;

        if ($fetcher instanceof GCECredentials) {
            return false;
        }

        return true;
    }

    /**
     * @param array $scopes
     * @param callable $authHttpHandler
     * @param array $authCacheOptions
     * @param CacheItemPoolInterface $authCache
     * @param string $quotaProject
     * @param array $defaultScopes
     * @return FetchAuthTokenInterface
     * @throws ValidationException
     */
    private static function buildApplicationDefaultCredentials(
        ?array $scopes = null,
        ?callable $authHttpHandler = null,
        ?array $authCacheOptions = null,
        ?CacheItemPoolInterface $authCache = null,
        $quotaProject = null,
        ?array $defaultScopes = null
    ) {
        try {
            return ApplicationDefaultCredentials::getCredentials(
                $scopes,
                $authHttpHandler,
                $authCacheOptions,
                $authCache,
                $quotaProject,
                $defaultScopes
            );
        } catch (DomainException $ex) {
            throw new ValidationException('Could not construct ApplicationDefaultCredentials', $ex->getCode(), $ex);
        }
    }

    /**
     * @param mixed $token
     */
    private static function isValid($token)
    {
        return is_array($token)
            && array_key_exists('access_token', $token);
    }

    /**
     * @param mixed $token
     */
    private static function isExpired($token)
    {
        return !(self::isValid($token)
            && array_key_exists('expires_at', $token)
            && $token['expires_at'] > time() + self::$eagerRefreshThresholdSeconds);
    }
}
