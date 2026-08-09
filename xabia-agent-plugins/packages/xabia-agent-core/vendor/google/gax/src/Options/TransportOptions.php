<?php

namespace Google\ApiCore\Options;

use ArrayAccess;
use Google\ApiCore\Options\TransportOptions\GrpcFallbackTransportOptions;
use Google\ApiCore\Options\TransportOptions\GrpcTransportOptions;
use Google\ApiCore\Options\TransportOptions\RestTransportOptions;

class TransportOptions implements ArrayAccess, OptionsInterface
{
    use OptionsTrait;

    private GrpcTransportOptions $grpc;

    private GrpcFallbackTransportOptions $grpcFallback;

    private RestTransportOptions $rest;

    /**
     * @param array $options {
     *    Config options used to construct the transport.
     *
     *    @type array $grpc
     *    @type array $grpcFallback
     *    @type array $rest
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
        $this->setGrpc(new GrpcTransportOptions($arr['grpc'] ?? []));
        $this->setGrpcFallback(new GrpcFallbackTransportOptions($arr['grpc-fallback'] ?? []));
        $this->setRest(new RestTransportOptions($arr['rest'] ?? []));
    }

    /**
     * @param GrpcTransportOptions $grpc
     *
     * @return $this
     */
    public function setGrpc(GrpcTransportOptions $grpc): self
    {
        $this->grpc = $grpc;

        return $this;
    }

    /**
     * @param GrpcFallbackTransportOptions $grpcFallback
     *
     * @return $this
     */
    public function setGrpcFallback(GrpcFallbackTransportOptions $grpcFallback): self
    {
        $this->grpcFallback = $grpcFallback;

        return $this;
    }

    /**
     * @param RestTransportOptions $rest
     *
     * @return $this
     */
    public function setRest(RestTransportOptions $rest): self
    {
        $this->rest = $rest;

        return $this;
    }
}
