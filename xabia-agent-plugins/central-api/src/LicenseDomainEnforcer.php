<?php

declare(strict_types=1);

namespace XabiaCentral;

/**
 * Alinea el dominio declarado por el cliente (body / X-Xabia-Source) con Origin/Referer
 * cuando estas cabeceras están presentes, para detectar copias de WordPress que reenvían otra identidad.
 */
final class LicenseDomainEnforcer
{
    /**
     * @return array{ok: true, effective_claim: string}|array{
     *   ok: false,
     *   code: string,
     *   pirate_domain: string,
     *   origin_host: string,
     *   referer_host: string
     * }
     */
    public static function reconcileClaimedWithHeaders(string $claimedUrlOrHost): array
    {
        $claimed = trim($claimedUrlOrHost);
        $originRaw = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        $refererRaw = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));

        $originHost = $originRaw !== '' ? Domain::normalize($originRaw) : '';
        $refererHost = $refererRaw !== '' ? Domain::normalize($refererRaw) : '';

        if ($originHost === '' && $refererHost === '') {
            return ['ok' => true, 'effective_claim' => $claimed];
        }

        if ($originHost !== '' && $refererHost !== ''
            && !Domain::domainsMatch($originHost, $refererHost)) {
            return [
                'ok'            => false,
                'code'          => 'header_origin_referer_mismatch',
                'pirate_domain' => $refererHost,
                'origin_host'  => $originHost,
                'referer_host' => $refererHost,
            ];
        }

        $httpHost = $originHost !== '' ? $originHost : $refererHost;
        if ($httpHost !== '' && !Domain::domainsMatch($httpHost, $claimed)) {
            return [
                'ok'            => false,
                'code'          => 'claim_header_mismatch',
                'pirate_domain' => $httpHost,
                'origin_host'   => $originHost,
                'referer_host'  => $refererHost,
            ];
        }

        return ['ok' => true, 'effective_claim' => $claimed];
    }
}
