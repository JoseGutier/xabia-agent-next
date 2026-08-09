<?php

namespace Google\ApiCore\Options\TransportOptions;

use ArrayAccess;
use Closure;
use Google\ApiCore\Options\OptionsInterface;
use Google\ApiCore\Options\OptionsTrait;
use Psr\Log\LoggerInterface;

/**
 * The RestTransportOptions class provides typing to the associative array of options used to
 * configure {@see \Google\ApiCore\Transport\RestTransport}.
 */
class RestTransportOptions implements ArrayAccess, OptionsInterface
{
    use OptionsTrait;

    private ?Closure $httpHandler;

    private ?Closure $clientCertSource;

    private ?string $restClientConfigPath;

    private null|false|LoggerInterface $logger;

    /**
     * @param array $options {
     *    Config options used to construct the REST transport.
     *
     *    @type callable $httpHandler
     *          A handler used to deliver PSR-7 requests.
     *    @type callable $clientCertSource
     *          A callable which returns the client cert as a string.
     *    @type string $restClientConfigPath
     *          The path to the REST client config file.
     *    @typo null|false|LoggerInterface
     *          A PSR-3 compliant logger instance.
     * }
     */
    public function __construct(array $options)
    {
        $this->fromArray($options);
    }

    /**
     * Sets the array of options as class properites.
     *
     * @param array $arr See the constructor for the list of supported options.
     */
    private function fromArray(array $arr): void
    {
        $this->setHttpHandler($arr['httpHandler'] ?? null);
        $this->setClientCertSource($arr['clientCertSource'] ?? null);
        $this->setRestClientConfigPath($arr['restClientConfigPath'] ?? null);
        $this->setLogger($arr['logger'] ?? null);
    }

    /**
     * @param ?callable $httpHandler
     *
     * @return $this
     */
    public function setHttpHandler(?callable $httpHandler): self
    {
        if (!is_null($httpHandler)) {
            $httpHandler = Closure::fromCallable($httpHandler);
        }
        $this->httpHandler = $httpHandler;

        return $this;
    }

    /**
     * @param ?callable $clientCertSource
     *
     * @return $this
     */
    public function setClientCertSource(?callable $clientCertSource): self
    {
        if (!is_null($clientCertSource)) {
            $clientCertSource = Closure::fromCallable($clientCertSource);
        }
        $this->clientCertSource = $clientCertSource;

        return $this;
    }

    /**
     * @param ?string $restClientConfigPath
     *
     * @return $this
     */
    public function setRestClientConfigPath(?string $restClientConfigPath): self
    {
        $this->restClientConfigPath = $restClientConfigPath;

        return $this;
    }

    /**
     * @param null|false|LoggerInterface $logger
     *
     * @return $this
     */
    public function setLogger(null|false|LoggerInterface $logger): self
    {
        $this->logger = $logger;

        return $this;
    }
}
