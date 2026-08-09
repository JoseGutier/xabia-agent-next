<?php

namespace Google\Auth;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;

/**
 * Sign a string using a Service Account private key.
 */
trait ServiceAccountSignerTrait
{
    /**
     * Sign a string using the service account private key.
     *
     * @param string $stringToSign
     * @param bool $forceOpenssl Whether to use OpenSSL regardless of
     *        whether phpseclib is installed. **Defaults to** `false`.
     * @return string
     */
    public function signBlob($stringToSign, $forceOpenssl = false)
    {
        $privateKey = $this->auth->getSigningKey();

        $signedString = '';
        if (class_exists(phpseclib3\Crypt\RSA::class) && !$forceOpenssl) {
            $key = PublicKeyLoader::load($privateKey);
            $rsa = $key->withHash('sha256')->withPadding(RSA::SIGNATURE_PKCS1);

            $signedString = $rsa->sign($stringToSign);
        } elseif (extension_loaded('openssl')) {
            openssl_sign($stringToSign, $signedString, $privateKey, 'sha256WithRSAEncryption');
        } else {
            
            throw new \RuntimeException('OpenSSL is not installed.');
        }
        

        return base64_encode($signedString);
    }
}
