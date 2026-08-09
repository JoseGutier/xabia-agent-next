<?php

namespace Google\ApiCore\Options;

use ArrayAccess;
use Google\ApiCore\CredentialsWrapper;
use Google\ApiCore\RetrySettings;

/**
 * The CallOptions class provides typing to the associative array of options
 * passed to transport RPC methods. See
 * {@see \Google\ApiCore\Transport\TransportInterface::startUnaryCall()},
 * {@see \Google\ApiCore\Transport\TransportInterface::startBidiStreamingCall()},
 * {@see \Google\ApiCore\Transport\TransportInterface::startClientStreamingCall()}, and
 * {@see \Google\ApiCore\Transport\TransportInterface::startServerStreamingCall()}.
 */
class CallOptions implements ArrayAccess, OptionsInterface
{
    use OptionsTrait;

    private array $headers;
    private ?int $timeoutMillis;
    private array $transportOptions;

    /** @var RetrySettings|array|null $retrySettings */
    private $retrySettings;

    /**
     * @param array $options {
     *     Call options
     *
     *     @type array<string, array<string>> $headers
     *           Key-value array containing headers.
     *     @type int $timeoutMillis
     *           The timeout in milliseconds for the call.
     *     @type array $transportOptions
     *           Transport-specific call options. See {@see CallOptions::setTransportOptions}.
     *     @type RetrySettings|array $retrySettings
     *           A retry settings override for the call. If $retrySettings is an
     *           array, the settings will be merged with the method's default
     *           retry settings. If $retrySettings is a RetrySettings object,
     *           that object will be used instead of the method defaults.
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
        $this->setHeaders($arr['headers'] ?? []);
        $this->setTimeoutMillis($arr['timeoutMillis'] ?? null);
        $this->setTransportOptions($arr['transportOptions'] ?? []);
        $this->setRetrySettings($arr['retrySettings'] ?? null);
    }

    /**
     * @param array $headers
     */
    public function setHeaders(array $headers): self
    {
        $this->headers = $headers;

        return $this;
    }

    /**
     * @param int|null $timeoutMillis
     */
    public function setTimeoutMillis(?int $timeoutMillis): self
    {
        $this->timeoutMillis = $timeoutMillis;

        return $this;
    }

    /**
     * @param array $transportOptions {
     *     Transport-specific call-time options.
     *
     *     @type array $grpcOptions
     *           Key-value pairs for gRPC-specific options passed as the `$options` argument to {@see \Grpc\BaseStub}
     *           request methods. Current options are `call_credentials_callback` and `timeout`.
     *           **NOTE**: This library sets `call_credentials_callback` using {@see CredentialsWrapper}, and `timeout`
     *           using the `timeoutMillis` call option, so these options are not very useful.
     *     @type array $grpcFallbackOptions
     *           Key-value pairs for gRPC fallback specific options passed as the `$options` argument to the
     *           `$httpHandler` callable. By default these are passed to {@see \GuzzleHttp\Client} as request options.
     *           See {@link https://docs.guzzlephp.org/en/stable/request-options.html}.
     *     @type array $restOptions
     *           Key-value pairs for REST-specific options passed as the `$options` argument to the `$httpHandler`
     *           callable. By default these are passed to {@see \GuzzleHttp\Client} as request options.
     *           See {@link https://docs.guzzlephp.org/en/stable/request-options.html}.
     * }
     */
    public function setTransportOptions(array $transportOptions): self
    {
        $this->transportOptions = $transportOptions;

        return $this;
    }

    /**
     * @deprecated use CallOptions::setTransportOptions
     */
    public function setTransportSpecificOptions(array $transportSpecificOptions): self
    {
        $this->setTransportOptions($transportSpecificOptions);

        return $this;
    }

    /**
     * @param RetrySettings|array|null $retrySettings
     *
     * @return $this
     */
    public function setRetrySettings($retrySettings): self
    {
        $this->retrySettings = $retrySettings;

        return $this;
    }
}
