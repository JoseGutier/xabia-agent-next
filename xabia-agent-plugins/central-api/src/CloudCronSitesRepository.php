<?php

declare(strict_types=1);

namespace XabiaCentral;

use PDO;
use Throwable;

/**
 * Sitios WordPress candidatos al Reloj Maestro (cloud-cron-trigger).
 */
final class CloudCronSitesRepository
{
    /**
     * @return list<array{
     *   license_id: int,
     *   license_key: string,
     *   installed_site_url: string,
     *   installed_domain: string,
     *   tokens_remaining: int,
     *   status: string,
     *   expiry_date: ?string
     * }>
     */
    public static function listTriggerableSites(int $limit = 500): array
    {
        $limit = max(1, min(2000, $limit));
        try {
            $sql = 'SELECT w.license_id, w.installed_site_url, w.installed_domain,
                           w.tokens_remaining, l.license_key, l.status, l.expiry_date
                    FROM xabia_wallets w
                    INNER JOIN xabia_licenses l ON l.id = w.license_id
                    WHERE w.installed_site_url IS NOT NULL
                      AND TRIM(w.installed_site_url) <> \'\'
                      AND w.cloud_cron_enabled = 1
                    ORDER BY w.last_seen_at DESC, w.license_id ASC
                    LIMIT ' . $limit;
            $st = Db::pdo()->query($sql);
        } catch (Throwable $e) {
            try {
                $sql = 'SELECT w.license_id, w.installed_site_url, w.installed_domain,
                               w.tokens_remaining, l.license_key, l.status, l.expiry_date
                        FROM xabia_wallets w
                        INNER JOIN xabia_licenses l ON l.id = w.license_id
                        WHERE w.installed_site_url IS NOT NULL
                          AND TRIM(w.installed_site_url) <> \'\'
                        ORDER BY w.last_seen_at DESC, w.license_id ASC
                        LIMIT ' . $limit;
                $st = Db::pdo()->query($sql);
            } catch (Throwable $e2) {
                return [];
            }
        }
        if ($st === false) {
            return [];
        }
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = trim((string) ($row['installed_site_url'] ?? ''));
            $key = trim((string) ($row['license_key'] ?? ''));
            if ($url === '' || $key === '') {
                continue;
            }
            $domain = trim((string) ($row['installed_domain'] ?? ''));
            if ($domain !== '' && LicenseRepository::isPendingAssignmentDomain($domain)) {
                continue;
            }
            $licenseRow = [
                'status'      => (string) ($row['status'] ?? ''),
                'expiry_date' => $row['expiry_date'] ?? null,
            ];
            $walletRow = [
                'tokens_remaining' => (int) ($row['tokens_remaining'] ?? 0),
            ];
            if (!LicenseRepository::allowsHubTokenConsumption($licenseRow, $walletRow)) {
                continue;
            }
            if (LicenseRepository::isSuspended($licenseRow)) {
                continue;
            }
            $out[] = [
                'license_id'         => (int) ($row['license_id'] ?? 0),
                'license_key'        => $key,
                'installed_site_url' => $url,
                'installed_domain'   => $domain,
                'tokens_remaining'   => (int) ($row['tokens_remaining'] ?? 0),
                'status'             => (string) ($row['status'] ?? ''),
                'expiry_date'        => $row['expiry_date'] !== null && $row['expiry_date'] !== ''
                    ? (string) $row['expiry_date']
                    : null,
            ];
        }

        return $out;
    }
}
