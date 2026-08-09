<?php

namespace Google\ApiCore\Transport;

use Google\ApiCore\ApiException;
use Google\ApiCore\ApiStatus;
use Google\ApiCore\Call;
use Google\ApiCore\ServiceAddressTrait;
use Google\ApiCore\ValidationException;
use Google\ApiCore\ValidationTrait;
use Google\Protobuf\Internal\Message;
use Google\Rpc\Status;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A transport that sends protobuf over HTTP 1.1 that can be used when full gRPC support
 * is not available.
 */
class GrpcFallbackTransport implements TransportInterface
{
    use ValidationTrait;
    use ServiceAddressTrait;
    use HttpUnaryTransportTrait;

    private string $baseUri;

    /**
     * @param string $baseUri
     * @param callable $httpHandler A handler used to deliver PSR-7 requests.
     */
    public function __construct(
        string $baseUri,
        callable $httpHandler,
    ) {
        $this->baseUri = $baseUri;
        $this->httpHandler = $httpHandler;
        $this->transportName = 'grpc-fallback';
    }

    /**
     * Builds a GrpcFallbackTransport.
     *
     * @param string $apiEndpoint
     *        The address of the API remote host, for example "example.googleapis.com".
     * @param array $config {
     *    Config options used to construct the grpc-fallback transport.
     *
     *    @type callable $httpHandler A handler used to deliver PSR-7 requests.
     * }
     * @return GrpcFallbackTransport
     * @throws ValidationException
     */
    public static function build(string $apiEndpoint, array $config = [])
    {
        $config += [
            'httpHandler'  => null,
            'clientCertSource' => null,
            'logger' => null,
        ];
        list($baseUri, $port) = self::normalizeServiceAddress($apiEndpoint);
        $httpHandler = $config['httpHandler'] ?: self::buildHttpHandlerAsync(logger: $config['logger']);
        $transport = new GrpcFallbackTransport("$baseUri:$port", $httpHandler);
        if ($config['clientCertSource']) {
            $transport->configureMtlsChannel($config['clientCertSource']);
        }
        return $transport;
    }

    /**
     * {@inheritdoc}
     */
    public function startUnaryCall(Call $call, array $options)
    {
        $httpHandler = $this->httpHandler;

        $options['requestId'] = crc32((string) spl_object_id($call) . getmypid());

        return $httpHandler(
            $this->buildRequest($call, $options),
            $this->getCallOptions($options)
        )->then(
            function (ResponseInterface $response) use ($options) {
                if (isset($options['metadataCallback'])) {
                    $metadataCallback = $options['metadataCallback'];
                    $metadataCallback($response->getHeaders());
                }
                return $response;
            }
        )->then(
            function (ResponseInterface $response) use ($call) {
                return $this->unpackResponse($call, $response);
            },
            function (\Exception $ex) {
                throw $this->transformException($ex);
            }
        );
    }

    /**
     * @param Call $call
     * @param array $options
     * @return RequestInterface
     */
    private function buildRequest(Call $call, array $options)
    {
        
        $headers = ['Content-Type' => 'application/x-protobuf'] + self::buildCommonHeaders($options);

        
        
        $headers += ['x-goog-api-client' => []];
        $headers['x-goog-api-client'][] = 'grpc-web';

        
        $uri = "https://{$this->baseUri}/\$rpc/{$call->getMethod()}";

        return new Request(
            'POST',
            $uri,
            $headers,
            $call->getMessage()->serializeToString()
        );
    }

    /**
     * @param Call $call
     * @param ResponseInterface $response
     * @return Message
     */
    private function unpackResponse(Call $call, ResponseInterface $response)
    {
        $decodeType = $call->getDecodeType();
        /** @var Message $responseMessage */
        $responseMessage = new $decodeType();
        $responseMessage->mergeFromString((string) $response->getBody());
        return $responseMessage;
    }

    /**
     * @param array $options
     * @return array
     */
    private function getCallOptions(array $options)
    {
        $callOptions = $options['transportOptions']['grpcFallbackOptions'] ?? [];

        if (isset($options['timeoutMillis'])) {
            $callOptions['timeout'] = $options['timeoutMillis'] / 1000;
        }

        if (isset($options['retryAttempt'])) {
            $callOptions['retryAttempt'] = $options['retryAttempt'];
        }

        if (isset($options['requestId'])) {
            $callOptions['requestId'] = $options['requestId'];
        }

        if ($this->clientCertSource) {
            list($cert, $key) = self::loadClientCertSource($this->clientCertSource);
            $callOptions['cert'] = $cert;
            $callOptions['key'] = $key;
        }

        return $callOptions;
    }

    /**
     * @param \Exception $ex
     * @return \Exception
     */
    private function transformException(\Exception $ex)
    {
        if ($ex instanceof RequestException && $ex->hasResponse()) {
            $res = $ex->getResponse();
            $body = (string) $res->getBody();
            $status = new Status();
            try {
                $status->mergeFromString($body);
                return ApiException::createFromRpcStatus($status);
            } catch (\Exception $parseException) {
                
                
                $code = ApiStatus::rpcCodeFromHttpStatusCode($res->getStatusCode());
                return ApiException::createFromApiResponse($body, $code, null, $parseException);
            }
        } else {
            return $ex;
        }
    }
}
