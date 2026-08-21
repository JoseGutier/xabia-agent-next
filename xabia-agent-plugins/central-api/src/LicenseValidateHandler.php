<?php

declare(strict_types=1);

namespace XabiaCentral;

final class LicenseValidateHandler
{
    public static function handle(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'POST' && $method !== 'GET') {
            Json::respond(405, ['error' => ['message' => 'Method Not Allowed', 'type' => 'method']]);

            return;
        }
        $rawBody = Json::readBody();
        $input = is_array($rawBody) ? $rawBody : [];
        $post = is_array($_POST) ? $_POST : [];
        $get = self::mergedQueryParams();
        $request = is_array($_REQUEST) ? $_REQUEST : [];
        $headerLicense = (string) ($_SERVER['HTTP_X_XABIA_LICENSE'] ?? '');
        $headerSource = (string) ($_SERVER['HTTP_X_XABIA_SOURCE'] ?? '');

        $key = PolarLicenseKey::normalize((string) self::firstString(
            [
                $input['license_key'] ?? null,
                $input['key'] ?? null,
                $post['license_key'] ?? null,
                $post['key'] ?? null,
                $get['license_key'] ?? null,
                $get['key'] ?? null,
                $request['license_key'] ?? null,
                $request['key'] ?? null,
                $headerLicense !== '' ? $headerLicense : null,
            ]
        ));
        $domainRaw = (string) self::firstString(
            [
                is_string($input['domain'] ?? null) ? (string) $input['domain'] : null,
                is_string($input['site_url'] ?? null) ? (string) $input['site_url'] : null,
                $post['domain'] ?? null,
                $post['site_url'] ?? null,
                $get['domain'] ?? null,
                $get['site_url'] ?? null,
                $request['domain'] ?? null,
                $request['site_url'] ?? null,
                $headerSource !== '' ? $headerSource : null,
            ]
        );
        $domainRaw = trim($domainRaw);

        if ($key === '' || $domainRaw === '') {
            $out = [
                'valid'             => false,
                'message'           => 'Faltan license_key (o key) y domain/site_url',
                'tokens_remaining'  => null,
                'expiry_date'       => null,
            ];
            if (Env::str('XABIA_DEBUG') !== '') {
                $out['debug'] = [
                    'query_string'   => (string) ($_SERVER['QUERY_STRING'] ?? ''),
                    'request_uri'    => (string) ($_SERVER['REQUEST_URI'] ?? ''),
                    'get_keys'       => array_keys($get),
                ];
            }
            Json::respond(400, $out);

            return;
        }

        $recon = LicenseDomainEnforcer::reconcileClaimedWithHeaders($domainRaw);
        if (!$recon['ok']) {
            self::respondUnauthorizedDomainFromEnforcer($key, $domainRaw, $recon);

            return;
        }
        $domainRaw = $recon['effective_claim'];

        $row = LicenseRepository::findActiveWithWalletByKey($key, $domainRaw);
        if ($row === null && LicenseRepository::bindPendingDomainIfEligible($key, $domainRaw)) {
            $row = LicenseRepository::findActiveWithWalletByKey($key, $domainRaw);
        }
        if ($row !== null && LicenseRepository::domainMatchesLicense($row, $domainRaw)) {
            if (LicenseRepository::isSuspended($row)) {
                Json::respond(200, [
                    'valid'             => false,
                    'success'           => false,
                    'active'            => false,
                    'core_expired'      => false,
                    'message'           => 'La licencia está suspendida.',
                    'reason'            => 'license_suspended',
                    'tokens_remaining'  => (int) $row['tokens_remaining'],
                    'expiry_date'       => LicenseRepository::expiryAsIso($row['expiry_date'] !== null ? (string) $row['expiry_date'] : null),
                ]);

                return;
            }
            if (LicenseRepository::isLicenseRowActive($row)) {
                self::respondLicenseSuccess($row, (string) $row['license_key'], false, $domainRaw);

                return;
            }
            if ((int) $row['tokens_remaining'] > 0) {
                self::respondLicenseSuccess($row, (string) $row['license_key'], true, $domainRaw);

                return;
            }
            Json::respond(200, [
                'valid'             => false,
                'success'           => false,
                'active'            => false,
                'core_expired'      => true,
                'message'           => 'La licencia Core caducó o está inactiva y no hay saldo de tokens.',
                'reason'            => 'license_not_active',
                'status'            => (string) ($row['status'] ?? ''),
                'tokens_remaining'  => 0,
                'expiry_date'       => LicenseRepository::expiryAsIso($row['expiry_date'] !== null ? (string) $row['expiry_date'] : null),
            ]);

            return;
        }

        self::respondLicenseValidationFailure($key, $domainRaw);
    }

    /**
     * Respuesta de validación positiva (Core vigente o “saldo eterno” con Core caducado).
     *
     * @param array<string, mixed> $row Fila de findActiveWithWalletByKey
     */
    private static function respondLicenseSuccess(array $row, string $licenseKey, bool $coreExpired, string $siteUrl = ''): void
    {
        $billingId = (int) ($row['wallet_license_id'] ?? $row['id'] ?? 0);
        if ($billingId > 0 && $siteUrl !== '') {
            WalletRepository::touchInstalledSite($billingId, $siteUrl);
        }
        $expiryIso = LicenseRepository::expiryAsIso($row['expiry_date'] !== null ? (string) $row['expiry_date'] : null);
        // Tokens are permanent: even with Core expired, active add-ons remain operable while balance exists.
        $addonActivations = LicenseRepository::activeAddonActivationsWithExpiryForLicense($licenseKey);
        $activeAddons = array_values(array_map(static fn (array $a): string => $a['addon_slug'], $addonActivations));
        $addonActivationsOut = [];
        foreach ($addonActivations as $a) {
            $addonActivationsOut[] = [
                'addon_slug'   => $a['addon_slug'],
                'expiry_date'  => LicenseRepository::expiryAsIso($a['expiry_date'] ?? null),
                'activated_at' => LicenseRepository::expiryAsIso($a['created_at'] ?? null),
            ];
        }
        $message = $coreExpired
            ? 'Licencia Core caducada o inactiva; el saldo de tokens sigue disponible.'
            : 'Licencia activa.';
        Json::respond(200, [
            'valid'             => true,
            'success'           => true,
            'active'            => !$coreExpired,
            'core_expired'      => $coreExpired,
            'license_id'        => (int) ($row['wallet_license_id'] ?? $row['id']),
            'wallet_license_id' => (int) ($row['wallet_license_id'] ?? $row['id']),
            'tokens_remaining'  => (int) $row['tokens_remaining'],
            'expiry_date'       => $expiryIso,
            'active_addons'     => $activeAddons,
            'addons'            => $activeAddons,
            'addon_activations' => $addonActivationsOut,
            'message'           => $message,
        ]);
    }

    /**
     * @param array{ok: false, code: string, pirate_domain: string, origin_host: string, referer_host: string} $recon
     */
    private static function respondUnauthorizedDomainFromEnforcer(string $key, string $originalClaim, array $recon): void
    {
        $base = [
            'valid'             => false,
            'success'           => false,
            'active'            => false,
            'tokens_remaining'  => null,
            'expiry_date'       => null,
        ];
        $all = LicenseRepository::findAllByLicenseKey($key);
        $registeredDomains = [];
        foreach ($all as $r) {
            if (!is_array($r)) {
                continue;
            }
            $d = trim((string) ($r['client_domain'] ?? ''));
            if ($d !== '') {
                $registeredDomains[] = $d;
            }
        }
        $registeredDomains = array_values(array_unique($registeredDomains));
        $summary = $registeredDomains[0] ?? '';
        if ($all !== []) {
            SecurityLog::licenseUnauthorizedDomain(
                $key,
                (string) ($recon['pirate_domain'] ?? ''),
                $originalClaim,
                $summary,
                [
                    'enforcer_code' => $recon['code'],
                    'origin_host'   => $recon['origin_host'],
                    'referer_host'  => $recon['referer_host'],
                ]
            );
        }
        Json::respond(200, array_merge($base, [
            'message'             => 'Dominio no autorizado para esta licencia.',
            'reason'              => 'unauthorized_domain',
            'registered_domain'   => $summary,
            'registered_domains'  => $registeredDomains,
        ]));
    }

    private static function respondLicenseValidationFailure(string $key, string $domainRaw): void
    {
        $base = [
            'valid'             => false,
            'tokens_remaining'  => null,
            'expiry_date'       => null,
        ];
        $all = LicenseRepository::findAllByLicenseKey($key);
        if ($all === []) {
            $out = array_merge($base, [
                'message' => 'Esta clave no existe en la base de datos a la que está conectado el hub (XABIA_DB_DSN). Importa schema/datos o corrige el DSN.',
                'reason'  => 'license_key_unknown',
            ]);
            if (Env::str('XABIA_DEBUG') !== '') {
                $out['debug'] = ['license_key_len' => strlen($key)];
            }
            Json::respond(200, $out);

            return;
        }

        $registeredDomains = array_values(array_unique(array_filter(array_map(
            static fn (array $r): string => trim((string) ($r['client_domain'] ?? '')),
            $all
        ))));
        $matchedMeta = null;
        foreach ($all as $r) {
            if (!is_array($r)) {
                continue;
            }
            if (LicenseRepository::domainMatchesLicense($r, $domainRaw)) {
                $matchedMeta = $r;

                break;
            }
        }
        if ($matchedMeta === null) {
            $registeredSummary = $registeredDomains[0] ?? '';
            SecurityLog::licenseUnauthorizedDomain(
                $key,
                Domain::normalize($domainRaw),
                $domainRaw,
                $registeredSummary,
                ['source' => 'validate_handler']
            );
            Json::respond(200, array_merge($base, [
                'message'             => 'El dominio indicado no coincide con ninguna fila de esta clave en xabia_licenses.',
                'reason'              => 'unauthorized_domain',
                'registered_domain'   => $registeredSummary,
                'registered_domains'  => $registeredDomains,
            ]));

            return;
        }
        if (!LicenseRepository::isLicenseRowActive($matchedMeta)) {
            Json::respond(200, array_merge($base, [
                'message' => 'La licencia existe pero está inactiva, suspendida o caducada.',
                'reason'  => 'license_not_active',
                'status'  => (string) ($matchedMeta['status'] ?? ''),
            ]));

            return;
        }
        if (!LicenseRepository::hasWalletForLicenseKey($key)) {
            Json::respond(200, array_merge($base, [
                'message' => 'La licencia y el dominio son correctos pero falta la fila en xabia_wallets para el license_id de facturación (MIN(id) por clave).',
                'reason'  => 'wallet_missing',
            ]));

            return;
        }

        Json::respond(200, array_merge($base, [
            'message' => 'No se pudo completar la validación (revisa tablas y restricciones).',
            'reason'  => 'validation_incomplete',
        ]));
    }

    /**
     * $_GET a veces llega vacío con ciertos rewrites/Nginx; rellenamos desde REQUEST_URI y QUERY_STRING.
     *
     * @return array<string, string|array<mixed>>
     */
    private static function mergedQueryParams(): array
    {
        $merged = [];
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $fromUri = parse_url($uri, PHP_URL_QUERY);
        if (is_string($fromUri) && $fromUri !== '') {
            $parsed = [];
            parse_str($fromUri, $parsed);
            if (is_array($parsed)) {
                $merged = array_merge($merged, $parsed);
            }
        }
        foreach (['REDIRECT_QUERY_STRING', 'QUERY_STRING'] as $serverKey) {
            $qs = (string) ($_SERVER[$serverKey] ?? '');
            if ($qs === '') {
                continue;
            }
            $parsed = [];
            parse_str($qs, $parsed);
            if (is_array($parsed) && $parsed !== []) {
                $merged = array_merge($merged, $parsed);
            }
        }
        if (is_array($_GET) && $_GET !== []) {
            $merged = array_merge($merged, $_GET);
        }

        return $merged;
    }

    /**
     * @param list<mixed> $candidates
     */
    private static function firstString(array $candidates): string
    {
        foreach ($candidates as $v) {
            if (!is_string($v)) {
                continue;
            }
            $t = trim($v);
            if ($t !== '') {
                return $t;
            }
        }

        return '';
    }
}
