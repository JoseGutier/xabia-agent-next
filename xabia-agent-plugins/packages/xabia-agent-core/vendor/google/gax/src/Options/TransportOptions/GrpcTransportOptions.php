<?php

namespace Google\ApiCore\Options\TransportOptions;

use ArrayAccess;
use Closure;
use Google\ApiCore\Options\OptionsInterface;
use Google\ApiCore\Options\OptionsTrait;
use Google\ApiCore\Transport\Grpc\UnaryInterceptorInterface;
use Grpc\Channel;
use Grpc\Interceptor;
use Psr\Log\LoggerInterface;

/**
 * The GrpcTransportOptions class provides typing to the associative array of options used to
 * configure {@see \Google\ApiCore\Transport\GrpcTransport}.
 */
class GrpcTransportOptions implements ArrayAccess, OptionsInterface
{
    use OptionsTrait;

    private array $stubOpts;

    private ?Channel $channel;

    private null|false|LoggerInterface $logger;

    /**
     * @var Interceptor[]|UnaryInterceptorInterface[]
     */
    private array $interceptors;

    private ?Closure $clientCertSource;

    /**
     * @param array $options {
     *    Config options used to construct the gRPC transport.
     *
     *    @type array $stubOpts Options used to construct the gRPC stub (see
     *          {@link https://grpc.github.io/grpc/core/group__grpc__arg__keys.html}).
     *    @type Channel $channel Grpc channel to be used.
     *    @type Interceptor[]|UnaryInterceptorInterface[] $interceptors *EXPERIMENTAL*
     *          Interceptors used to intercept RPC invocations before a call starts.
     *          Please note that implementations of
     *          {@see \Google\ApiCore\Transport\Grpc\UnaryInterceptorInterface} are
     *          considered deprecated and support will be removed in a future
     *          release. To prepare for this, please take the time to convert
     *          `UnaryInterceptorInterface` implementations over to a class which
     *          extends {@see Grpc\Interceptor}.
     *    @type callable $clientCertSource A callable which returns the client cert as a string.
     *    @type null|false|LoggerInterface A PSR-3 Logger Interface.
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
        $this->setStubOpts($arr['stubOpts'] ?? []);
        $this->setChannel($arr['channel'] ?? null);
        $this->setInterceptors($arr['interceptors'] ?? []);
        $this->setClientCertSource($arr['clientCertSource'] ?? null);
        $this->setLogger($arr['logger'] ?? null);
    }

    /**
     * @param array $stubOpts
     *
     * @return $this
     */
    public function setStubOpts(array $stubOpts): self
    {
        $this->stubOpts = $stubOpts;

        return $this;
    }

    /**
     * @param ?Channel $channel
     *
     * @return $this
     */
    public function setChannel(?Channel $channel): self
    {
        $this->channel = $channel;

        return $this;
    }

    /**
     * @param Interceptor[]|UnaryInterceptorInterface[] $interceptors
     *
     * @return $this
     */
    public function setInterceptors(array $interceptors): self
    {
        $this->interceptors = $interceptors;

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
