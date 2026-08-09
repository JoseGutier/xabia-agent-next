<?php

namespace Google\Auth\CredentialSource;

use Google\Auth\ExternalAccountCredentialSourceInterface;
use Google\Auth\HttpHandler\HttpClientCache;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use GuzzleHttp\Psr7\Request;

/**
 * Authenticates requests using AWS credentials.
 */
class AwsNativeSource implements ExternalAccountCredentialSourceInterface
{
    private const CRED_VERIFICATION_QUERY = 'Action=GetCallerIdentity&Version=2011-06-15';

    private string $audience;
    private string $regionalCredVerificationUrl;
    private ?string $regionUrl;
    private ?string $securityCredentialsUrl;
    private ?string $imdsv2SessionTokenUrl;

    /**
     * @param string $audience The audience for the credential.
     * @param string $regionalCredVerificationUrl The regional AWS GetCallerIdentity action URL used to determine the
     *                                            AWS account ID and its roles. This is not called by this library, but
     *                                            is sent in the subject token to be called by the STS token server.
     * @param string|null $regionUrl This URL should be used to determine the current AWS region needed for the signed
     *                               request construction.
     * @param string|null $securityCredentialsUrl The AWS metadata server URL used to retrieve the access key, secret
     *                                            key and security token needed to sign the GetCallerIdentity request.
     * @param string|null $imdsv2SessionTokenUrl Presence of this URL enforces the auth libraries to fetch a Session
     *                                           Token from AWS. This field is required for EC2 instances using IMDSv2.
     */
    public function __construct(
        string $audience,
        string $regionalCredVerificationUrl,
        ?string $regionUrl = null,
        ?string $securityCredentialsUrl = null,
        ?string $imdsv2SessionTokenUrl = null
    ) {
        $this->audience = $audience;
        $this->regionalCredVerificationUrl = $regionalCredVerificationUrl;
        $this->regionUrl = $regionUrl;
        $this->securityCredentialsUrl = $securityCredentialsUrl;
        $this->imdsv2SessionTokenUrl = $imdsv2SessionTokenUrl;
    }

    public function fetchSubjectToken(?callable $httpHandler = null): string
    {
        if (is_null($httpHandler)) {
            $httpHandler = HttpHandlerFactory::build(HttpClientCache::getHttpClient());
        }

        $headers = [];
        if ($this->imdsv2SessionTokenUrl) {
            $headers = [
                'X-aws-ec2-metadata-token' => self::getImdsV2SessionToken($this->imdsv2SessionTokenUrl, $httpHandler)
            ];
        }

        if (!$signingVars = self::getSigningVarsFromEnv()) {
            if (!$this->securityCredentialsUrl) {
                throw new \LogicException('Unable to get credentials from ENV, and no security credentials URL provided');
            }
            $signingVars = self::getSigningVarsFromUrl(
                $httpHandler,
                $this->securityCredentialsUrl,
                self::getRoleName($httpHandler, $this->securityCredentialsUrl, $headers),
                $headers
            );
        }

        if (!$region = self::getRegionFromEnv()) {
            if (!$this->regionUrl) {
                throw new \LogicException('Unable to get region from ENV, and no region URL provided');
            }
            $region = self::getRegionFromUrl($httpHandler, $this->regionUrl, $headers);
        }
        $url = str_replace('{region}', $region, $this->regionalCredVerificationUrl);
        $host = parse_url($url)['host'] ?? '';

        
        [$accessKeyId, $secretAccessKey, $securityToken] = $signingVars;
        $headers = self::getSignedRequestHeaders($region, $host, $accessKeyId, $secretAccessKey, $securityToken);

        
        $headers['x-goog-cloud-target-resource'] = $this->audience;

        
        $formattedHeaders = array_map(
            fn ($k, $v) => ['key' => $k, 'value' => $v],
            array_keys($headers),
            $headers,
        );

        $request = [
            'headers' => $formattedHeaders,
            'method' => 'POST',
            'url' => $url,
        ];

        return urlencode(json_encode($request) ?: '');
    }

    /**
     * @internal
     */
    public static function getImdsV2SessionToken(string $imdsV2Url, callable $httpHandler): string
    {
        $headers = [
            'X-aws-ec2-metadata-token-ttl-seconds' => '21600'
        ];
        $request = new Request(
            'PUT',
            $imdsV2Url,
            $headers
        );

        $response = $httpHandler($request);
        return (string) $response->getBody();
    }

    /**
     * @see http://docs.aws.amazon.com/general/latest/gr/sigv4-create-canonical-request.html
     *
     * @internal
     *
     * @return array<string, string>
     */
    public static function getSignedRequestHeaders(
        string $region,
        string $host,
        string $accessKeyId,
        string $secretAccessKey,
        ?string $securityToken
    ): array {
        $service = 'sts';

        
        $amzdate = gmdate('Ymd\THis\Z');
        $datestamp = gmdate('Ymd'); 

        
        
        
        $canonicalHeaders = sprintf("host:%s\nx-amz-date:%s\n", $host, $amzdate);
        if ($securityToken) {
            $canonicalHeaders .= sprintf("x-amz-security-token:%s\n", $securityToken);
        }

        
        
        
        
        
        $signedHeaders = 'host;x-amz-date';
        if ($securityToken) {
            $signedHeaders .= ';x-amz-security-token';
        }

        
        
        $payloadHash = hash('sha256', '');

        
        $canonicalRequest = implode("\n", [
            'POST', 
            '/',   
            self::CRED_VERIFICATION_QUERY, 
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash
        ]);

        
        
        
        $algorithm = 'AWS4-HMAC-SHA256';
        $scope = implode('/', [$datestamp, $region, $service, 'aws4_request']);
        $stringToSign = implode("\n", [$algorithm, $amzdate, $scope, hash('sha256', $canonicalRequest)]);

        
        
        
        $signingKey = self::getSignatureKey($secretAccessKey, $datestamp, $region, $service);

        
        $signature = bin2hex(self::hmacSign($signingKey, $stringToSign));

        
        
        
        
        $authorizationHeader = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $algorithm,
            $accessKeyId,
            $scope,
            $signedHeaders,
            $signature
        );

        
        
        
        
        $headers = [
            'host' => $host,
            'x-amz-date' => $amzdate,
            'Authorization' => $authorizationHeader,
        ];
        if ($securityToken) {
            $headers['x-amz-security-token'] = $securityToken;
        }

        return $headers;
    }

    /**
     * @internal
     */
    public static function getRegionFromEnv(): ?string
    {
        $region = getenv('AWS_REGION');
        if (empty($region)) {
            $region = getenv('AWS_DEFAULT_REGION');
        }
        return $region ?: null;
    }

    /**
     * @internal
     *
     * @param callable $httpHandler
     * @param string $regionUrl
     * @param array<string, string|string[]> $headers Request headers to send in with the request.
     */
    public static function getRegionFromUrl(callable $httpHandler, string $regionUrl, array $headers): string
    {
        
        $regionRequest = new Request('GET', $regionUrl, $headers);
        $regionResponse = $httpHandler($regionRequest);

        
        
        return substr((string) $regionResponse->getBody(), 0, -1);
    }

    /**
     * @internal
     *
     * @param callable $httpHandler
     * @param string $securityCredentialsUrl
     * @param array<string, string|string[]> $headers Request headers to send in with the request.
     */
    public static function getRoleName(callable $httpHandler, string $securityCredentialsUrl, array $headers): string
    {
        
        $roleRequest = new Request('GET', $securityCredentialsUrl, $headers);
        $roleResponse = $httpHandler($roleRequest);
        $roleName = (string) $roleResponse->getBody();

        return $roleName;
    }

    /**
     * @internal
     *
     * @param callable $httpHandler
     * @param string $securityCredentialsUrl
     * @param array<string, string|string[]> $headers Request headers to send in with the request.
     * @return array{string, string, ?string}
     */
    public static function getSigningVarsFromUrl(
        callable $httpHandler,
        string $securityCredentialsUrl,
        string $roleName,
        array $headers
    ): array {
        
        $credsRequest = new Request(
            'GET',
            $securityCredentialsUrl . '/' . $roleName,
            $headers
        );
        $credsResponse = $httpHandler($credsRequest);
        $awsCreds = json_decode((string) $credsResponse->getBody(), true);
        return [
            $awsCreds['AccessKeyId'], 
            $awsCreds['SecretAccessKey'], 
            $awsCreds['Token'], 
        ];
    }

    /**
     * @internal
     *
     * @return array{string, string, ?string}
     */
    public static function getSigningVarsFromEnv(): ?array
    {
        $accessKeyId = getenv('AWS_ACCESS_KEY_ID');
        $secretAccessKey = getenv('AWS_SECRET_ACCESS_KEY');
        if ($accessKeyId && $secretAccessKey) {
            return [
                $accessKeyId,
                $secretAccessKey,
                getenv('AWS_SESSION_TOKEN') ?: null, 
            ];
        }

        return null;
    }

    /**
     * Gets the unique key for caching
     * For AwsNativeSource the values are:
     * Imdsv2SessionTokenUrl.SecurityCredentialsUrl.RegionUrl.RegionalCredVerificationUrl
     *
     * @return string
     */
    public function getCacheKey(): string
    {
        return ($this->imdsv2SessionTokenUrl ?? '') .
            '.' . ($this->securityCredentialsUrl ?? '') .
            '.' . $this->regionUrl .
            '.' . $this->regionalCredVerificationUrl;
    }

    /**
     * Return HMAC hash in binary string
     */
    private static function hmacSign(string $key, string $msg): string
    {
        return hash_hmac('sha256', self::utf8Encode($msg), $key, true);
    }

    /**
     * @TODO add a fallback when mbstring is not available
     */
    private static function utf8Encode(string $string): string
    {
        return (string) mb_convert_encoding($string, 'UTF-8', 'ISO-8859-1');
    }

    private static function getSignatureKey(
        string $key,
        string $dateStamp,
        string $regionName,
        string $serviceName
    ): string {
        $kDate = self::hmacSign(self::utf8Encode('AWS4' . $key), $dateStamp);
        $kRegion = self::hmacSign($kDate, $regionName);
        $kService = self::hmacSign($kRegion, $serviceName);
        $kSigning = self::hmacSign($kService, 'aws4_request');

        return $kSigning;
    }
}
