<?php

declare(strict_types=1);

namespace XabiaCentral;

/**
 * Reportes del plugin (embeddings batch, etc.). Valida licencia en xabia_licenses,
 * descuenta xabia_wallets por id numérico y registra filas en xabia_usage_log (esquema Hub).
 */
final class UsageReportHandler
{
    public static function handle(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            Json::respond(405, ['error' => ['message' => 'Method Not Allowed', 'type' => 'method']]);

            return;
        }
        $input = Json::readBody();
        if ($input === null) {
            Json::respond(400, ['ok' => false, 'message' => 'JSON inválido']);

            return;
        }
        $key = trim((string) ($input['license_key'] ?? ''));
        $siteUrl = trim((string) ($input['site_url'] ?? ''));
        if ($key === '' || $siteUrl === '') {
            Json::respond(400, ['ok' => false, 'message' => 'Faltan license_key o site_url']);

            return;
        }

        $recon = LicenseDomainEnforcer::reconcileClaimedWithHeaders($siteUrl);
        if (!$recon['ok']) {
            self::respondUsageUnauthorizedFromEnforcer($key, $siteUrl, $recon);

            return;
        }
        $siteUrl = $recon['effective_claim'];

        $all = LicenseRepository::findAllByLicenseKey($key);
        if ($all === []) {
            Json::respond(403, ['ok' => false, 'message' => 'Licencia inválida']);

            return;
        }

        $domainRow = null;
        foreach ($all as $r) {
            if (!is_array($r)) {
                continue;
            }
            if (LicenseRepository::domainMatchesLicense($r, $siteUrl)) {
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
                $key,
                Domain::normalize($siteUrl),
                $siteUrl,
                $regs[0] ?? '',
                ['source' => 'usage_report']
            );
            Json::respond(403, [
                'ok'                   => false,
                'message'              => 'Dominio no autorizado para esta licencia',
                'reason'               => 'unauthorized_domain',
                'registered_domain'    => $regs[0] ?? '',
                'registered_domains'   => $regs,
            ]);

            return;
        }

        if (LicenseRepository::isSuspended($domainRow)) {
            Json::respond(403, [
                'ok'      => false,
                'message' => 'La licencia está suspendida.',
                'reason'  => 'license_suspended',
            ]);

            return;
        }

        $row = LicenseRepository::findActiveWithWalletByKey($key, $siteUrl);
        if ($row !== null && LicenseRepository::isSuspended($row)) {
            Json::respond(403, [
                'ok'      => false,
                'message' => 'La licencia está suspendida.',
                'reason'  => 'license_suspended',
            ]);

            return;
        }

        if (!LicenseRepository::allowsHubTokenConsumption($domainRow, $row)) {
            Json::respond(403, ['ok' => false, 'message' => 'Licencia inválida']);

            return;
        }

        if ($row === null) {
            Json::respond(403, ['ok' => false, 'message' => 'Licencia inválida']);

            return;
        }

        $pt = (int) ($input['prompt_tokens'] ?? 0);
        $ct = (int) ($input['completion_tokens'] ?? 0);
        $total = (int) ($input['total_tokens'] ?? 0);
        if ($total < 1) {
            $total = $pt + $ct;
        }
        if ($total < 1) {
            Json::respond(200, [
                'ok'                => true,
                'tokens_remaining'  => (int) $row['tokens_remaining'],
                'expiry_date'       => LicenseRepository::expiryAsIso($row['expiry_date'] !== null ? (string) $row['expiry_date'] : null),
                'message'           => 'Sin tokens a descontar',
            ]);

            return;
        }

        $context = (string) ($input['context'] ?? 'usage_report');
        $projectId = isset($input['project_id']) ? (string) $input['project_id'] : null;
        $billingLicenseId = (int) ($row['wallet_license_id'] ?? $row['id'] ?? 0);
        if ($billingLicenseId < 1) {
            $billingLicenseId = (int) $row['id'];
        }
        $after = WalletRepository::deduct(
            $billingLicenseId,
            $total,
            substr($context, 0, 64),
            $siteUrl,
            $projectId,
            ['plugin_version' => (string) ($input['plugin_version'] ?? '')]
        );
        if ($after === null) {
            Json::respond(500, ['ok' => false, 'message' => 'Error al actualizar saldo']);

            return;
        }

        Json::respond(200, [
            'ok'               => true,
            'tokens_remaining' => $after,
            'expiry_date'      => LicenseRepository::expiryAsIso($row['expiry_date'] !== null ? (string) $row['expiry_date'] : null),
        ]);
    }

    /**
     * @param array{ok: false, code: string, pirate_domain: string, origin_host: string, referer_host: string} $recon
     */
    private static function respondUsageUnauthorizedFromEnforcer(string $key, string $originalSiteUrl, array $recon): void
    {
        $all = LicenseRepository::findAllByLicenseKey($key);
        $regs = array_values(array_unique(array_filter(array_map(
            static fn (array $r): string => trim((string) ($r['client_domain'] ?? '')),
            $all
        ))));
        $summary = $regs[0] ?? '';
        if ($all !== []) {
            SecurityLog::licenseUnauthorizedDomain(
                $key,
                (string) ($recon['pirate_domain'] ?? ''),
                $originalSiteUrl,
                $summary,
                [
                    'source'        => 'usage_report',
                    'enforcer_code' => $recon['code'],
                ]
            );
        }
        Json::respond(403, [
            'ok'                  => false,
            'message'             => 'Dominio no autorizado para esta licencia',
            'reason'              => 'unauthorized_domain',
            'registered_domain'   => $summary,
            'registered_domains'  => $regs,
        ]);
    }
}
