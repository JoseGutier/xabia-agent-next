<?php

namespace Google\ApiCore\Transport;

use Exception;
use Google\ApiCore\Call;
use Google\ApiCore\ValidationException;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use Psr\Log\LoggerInterface;

/**
 * A trait for shared functionality between transports that support only unary RPCs using simple
 * HTTP requests.
 *
 * @internal
 */
trait HttpUnaryTransportTrait
{
    private $httpHandler;
    private $transportName;
    private $clientCertSource;

    /**
     * {@inheritdoc}
     * @return never
     * @throws \BadMethodCallException
     */
    public function startClientStreamingCall(Call $call, array $options)
    {
        $this->throwUnsupportedException();
    }

    /**
     * {@inheritdoc}
     * @return never
     * @throws \BadMethodCallException
     */
    public function startServerStreamingCall(Call $call, array $options)
    {
        $this->throwUnsupportedException();
    }

    /**
     * {@inheritdoc}
     * @return never
     * @throws \BadMethodCallException
     */
    public function startBidiStreamingCall(Call $call, array $options)
    {
        $this->throwUnsupportedException();
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        
    }

    /**
     * @param array $options
     * @return array
     */
    private static function buildCommonHeaders(array $options)
    {
        $headers = $options['headers'] ?? [];

        if (!is_array($headers)) {
            throw new \InvalidArgumentException(
                'The "headers" option must be an array'
            );
        }

        
        if (!isset($headers['Authorization']) && isset($options['credentialsWrapper'])) {
            $credentialsWrapper = $options['credentialsWrapper'];
            $audience = $options['audience'] ?? null;
            $callback = $credentialsWrapper
                ->getAuthorizationHeaderCallback($audience);
            
            
            unset($headers['authorization']);
            
            $authHeaders = empty($callback) ? [] : $callback();
            if (!is_array($authHeaders)) {
                throw new \UnexpectedValueException(
                    'Expected array response from authorization header callback'
                );
            }
            $headers += $authHeaders;
        }

        return $headers;
    }

    /**
     * @return callable
     * @throws ValidationException
     */
    private static function buildHttpHandlerAsync(null|false|LoggerInterface $logger = null)
    {
        try {
            return [HttpHandlerFactory::build(logger: $logger), 'async'];
        } catch (Exception $ex) {
            throw new ValidationException('Failed to build HttpHandler', $ex->getCode(), $ex);
        }
    }

    /**
     * Set the path to a client certificate.
     *
     * @param callable $clientCertSource
     */
    private function configureMtlsChannel(callable $clientCertSource)
    {
        $this->clientCertSource = $clientCertSource;
    }

    /**
     * @return never
     * @throws \BadMethodCallException
     */
    private function throwUnsupportedException()
    {
        throw new \BadMethodCallException(
            "Streaming calls are not supported while using the {$this->transportName} transport."
        );
    }

    private static function loadClientCertSource(callable $clientCertSource)
    {
        $certFile = tempnam(sys_get_temp_dir(), 'cert');
        $keyFile = tempnam(sys_get_temp_dir(), 'key');
        list($cert, $key) = call_user_func($clientCertSource);
        file_put_contents($certFile, $cert);
        file_put_contents($keyFile, $key);

        
        return [$certFile, $keyFile];
    }
}
