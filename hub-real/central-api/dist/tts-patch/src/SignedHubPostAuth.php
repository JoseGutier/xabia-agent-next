<?php

declare(strict_types=1);

namespace XabiaCentral;

/**
 * Licencia + dominio + firma HMAC para POST al Hub (proxy, conocimiento, etc.).
 *
 * @phpstan-type LicensedCtx array{license_key: string, source_url: string, row: array<string, mixed>, billing_license_id: int}
 */
final class SignedHubPostAuth
{
    /**
     * @return LicensedCtx|null null si ya se envió error JSON
     */
    public static function validate(string $rawBody): ?array
    {
        $licenseKey = trim((string) ($_SERVER['HTTP_X_XABIA_LICENSE'] ?? ''));
        $sourceUrl = trim((string) ($_SERVER['HTTP_X_XABIA_SOURCE'] ?? ''));
        if ($licenseKey === '') {
            return self::reject(401, [
                'error' => [
                    'message' => 'Cabecera X-Xabia-License obligatoria',
                    'type'    => 'invalid_request',
                    'code'    => 'missing_license_header',
                ],
            ], 'missing_license_header');
        }
        if ($sourceUrl === '') {
            return self::reject(401, [
                'error' => [
                    'message' => 'Cabecera X-Xabia-Source obligatoria',
                    'type'    => 'invalid_request',
                    'code'    => 'missing_source_header',
                ],
            ], 'missing_source_header');
        }

        $recon = LicenseDomainEnforcer::reconcileClaimedWithHeaders($sourceUrl);
        if (!$recon['ok']) {
            self::respondEnforcerFailure($licenseKey, $sourceUrl, $recon);

            return null;
        }
        $sourceUrl = $recon['effective_claim'];

        $all = LicenseRepository::findAllByLicenseKey($licenseKey);
        if ($all === []) {
            return self::reject(403, [
                'error' => [
                    'message' => 'Licencia inválida o inactiva',
                    'type'    => 'forbidden',
                    'code'    => 'invalid_license',
                ],
            ], 'invalid_license');
        }

        $domainRow = null;
        foreach ($all as $r) {
            if (!is_array($r)) {
                continue;
            }
            if (LicenseRepository::domainMatchesLicense($r, $sourceUrl)) {
                $domainRow = $r;

                break;
            }
        }

        if ($domainRow === null) {
            $regs = array_values(array_unique(array_filter(array_map(
                static fn (array $r): string => trim((string) ($r['client_domain'] ?? '')),
                $all
            ))));
            SecurityLog::licenseUnauthorizedDomain(
                $licenseKey,
                Domain::normalize($sourceUrl),
                $sourceUrl,
                $regs[0] ?? '',
                ['source' => 'signed_hub_post']
            );
            Json::respond(403, [
                'error' => [
                    'message'             => 'Dominio no autorizado para esta licencia',
                    'type'                => 'forbidden',
                    'code'                => 'unauthorized_domain',
                    'registered_domain'   => $regs[0] ?? '',
                    'registered_domains'  => $regs,
                ],
            ]);
            error_log('[xabia-hub-auth] unauthorized_domain source=' . $sourceUrl . ' registered=' . json_encode($regs));

            return null;
        }

        if (LicenseRepository::isSuspended($domainRow)) {
            return self::reject(403, [
                'error' => [
                    'message' => 'La licencia está suspendida.',
                    'type'    => 'forbidden',
                    'code'    => 'license_suspended',
                ],
            ], 'license_suspended_domain_row');
        }

        $row = LicenseRepository::findActiveWithWalletByKey($licenseKey, $sourceUrl);
        if ($row !== null && LicenseRepository::isSuspended($row)) {
            return self::reject(403, [
                'error' => [
                    'message' => 'La licencia está suspendida.',
                    'type'    => 'forbidden',
                    'code'    => 'license_suspended',
                ],
            ], 'license_suspended_wallet_row');
        }

        if (!LicenseRepository::allowsHubTokenConsumption($domainRow, $row)) {
            return self::reject(403, [
                'error' => [
                    'message' => 'Licencia inválida o inactiva',
                    'type'    => 'forbidden',
                    'code'    => 'invalid_license',
                ],
            ], 'token_consumption_not_allowed');
        }

        if ($row === null) {
            if (!LicenseRepository::hasWalletForLicenseKey($licenseKey)) {
                return self::reject(403, [
                    'error' => [
                        'message' => 'Falta cartera de tokens para esta licencia',
                        'type'    => 'forbidden',
                        'code'    => 'wallet_missing',
                    ],
                ], 'wallet_missing');
            }
            return self::reject(403, [
                'error' => [
                    'message' => 'Licencia inválida o inactiva',
                    'type'    => 'forbidden',
                    'code'    => 'invalid_license',
                ],
            ], 'wallet_row_missing');
        }

        if (!HubClientSignature::verify($rawBody, $licenseKey, $sourceUrl, $licenseKey)) {
            return self::reject(403, [
                'error' => [
                    'message' => 'Firma del cliente inválida o ausente',
                    'type'    => 'forbidden',
                    'code'    => 'invalid_proxy_signature',
                ],
            ], 'invalid_proxy_signature');
        }

        $billingLicenseId = (int) ($row['wallet_license_id'] ?? $row['id'] ?? 0);
        if ($billingLicenseId < 1) {
            $billingLicenseId = (int) $row['id'];
        }

        return [
            'license_key'        => $licenseKey,
            'source_url'         => $sourceUrl,
            'row'                => $row,
            'billing_license_id' => $billingLicenseId,
        ];
    }

    /**
     * @param array{ok: false, code: string, pirate_domain: string, origin_host: string, referer_host: string} $recon
     */
    private static function respondEnforcerFailure(string $licenseKey, string $originalSource, array $recon): void
    {
        $all = LicenseRepository::findAllByLicenseKey($licenseKey);
        $regs = array_values(array_unique(array_filter(array_map(
            static fn (array $r): string => trim((string) ($r['client_domain'] ?? '')),
            $all
        ))));
        $summary = $regs[0] ?? '';
        if ($all !== []) {
            SecurityLog::licenseUnauthorizedDomain(
                $licenseKey,
                (string) ($recon['pirate_domain'] ?? ''),
                $originalSource,
                $summary,
                [
                    'source'        => 'signed_hub_post',
                    'enforcer_code' => $recon['code'],
                ]
            );
        }
        Json::respond(403, [
            'error' => [
                'message'            => 'Dominio no autorizado para esta licencia',
                'type'               => 'forbidden',
                'code'               => 'unauthorized_domain',
                'registered_domain'  => $summary,
                'registered_domains' => $regs,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function reject(int $httpCode, array $payload, string $logCode): null
    {
        $error = $payload['error'] ?? $payload;
        error_log('[xabia-hub-auth] ' . $logCode . ' ' . json_encode($error, JSON_UNESCAPED_UNICODE));
        Json::respond($httpCode, $payload);

        return null;
    }
}
