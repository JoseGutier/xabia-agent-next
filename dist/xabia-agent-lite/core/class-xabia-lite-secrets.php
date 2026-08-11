<?php
/**
 * Cifrado local de secretos LITE (BYOK). Sin dependencias Premium ni Hub.
 *
 * Usa sales criptográficas de WordPress (wp_salt) + OpenSSL AES-256-CBC.
 * La clave en claro solo existe en memoria durante peticiones servidor autorizadas.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Lite_Secrets {

    private const CIPHER = 'AES-256-CBC';

    public static function encrypt(string $plaintext): string {
        $plaintext = trim($plaintext);
        if ($plaintext === '') {
            return '';
        }
        if (!function_exists('openssl_encrypt') || !function_exists('openssl_random_pseudo_bytes')) {
            return '';
        }

        $iv_length = openssl_cipher_iv_length(self::CIPHER);
        if ($iv_length === false || $iv_length < 1) {
            return '';
        }

        $iv = openssl_random_pseudo_bytes($iv_length);
        if ($iv === false) {
            return '';
        }

        $cipher = openssl_encrypt($plaintext, self::CIPHER, self::encryption_key(), OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            return '';
        }

        return base64_encode($iv . $cipher);
    }

    public static function decrypt(string $encoded): string {
        $encoded = trim($encoded);
        if ($encoded === '') {
            return '';
        }
        if (!function_exists('openssl_decrypt')) {
            return '';
        }

        $raw = base64_decode($encoded, true);
        if ($raw === false || $raw === '') {
            return '';
        }

        $iv_length = openssl_cipher_iv_length(self::CIPHER);
        if ($iv_length === false || strlen($raw) <= $iv_length) {
            return '';
        }

        $iv = substr($raw, 0, $iv_length);
        $cipher = substr($raw, $iv_length);
        $plain = openssl_decrypt($cipher, self::CIPHER, self::encryption_key(), OPENSSL_RAW_DATA, $iv);

        return is_string($plain) ? $plain : '';
    }

    /**
     * Derivación de clave atada al sitio (rotación al cambiar salts en wp-config).
     */
    private static function encryption_key(): string {
        $material = wp_salt('auth') . '|' . wp_salt('secure_auth') . '|' . wp_salt('logged_in');

        return hash('sha256', $material, true);
    }
}
