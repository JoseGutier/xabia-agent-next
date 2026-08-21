<?php

declare(strict_types=1);

namespace XabiaCentral;

use PDO;
use Throwable;

final class LicenseRepository
{
    /** Dominio provisional cuando Polar no envía URL en el checkout (se vincula en la primera validación WP). */
    public const PENDING_ASSIGNMENT_DOMAIN = 'pending.unassigned';

    public static function isPendingAssignmentDomain(string $domain): bool
    {
        $domain = strtolower(trim($domain));

        return $domain === self::PENDING_ASSIGNMENT_DOMAIN || str_starts_with($domain, 'pending.');
    }

    /**
     * Primera activación: si la licencia solo tiene dominio pending.*, lo sustituye por el sitio que valida.
     */
    public static function bindPendingDomainIfEligible(string $licenseKey, string $claimedUrl): bool
    {
        $licenseKey = PolarLicenseKey::normalize($licenseKey);
        $claimed = Domain::normalize($claimedUrl);
        if ($licenseKey === '' || $claimed === '' || self::isPendingAssignmentDomain($claimed)) {
            return false;
        }
        $rows = self::findAllByLicenseKey($licenseKey);
        if ($rows === []) {
            return false;
        }
        $hasPending = false;
        foreach ($rows as $r) {
            $d = trim((string) ($r['client_domain'] ?? ''));
            if ($d === '') {
                continue;
            }
            if (self::isPendingAssignmentDomain($d)) {
                $hasPending = true;

                continue;
            }
            if (!Domain::domainsMatch($d, $claimed)) {
                return false;
            }
        }
        if (!$hasPending) {
            return false;
        }
        try {
            $pdo = Db::pdo();
            $st = $pdo->prepare(
                'UPDATE xabia_licenses SET client_domain = :new WHERE license_key = :k AND client_domain LIKE :pending'
            );
            $st->execute([
                ':new'     => $claimed,
                ':k'       => $licenseKey,
                ':pending' => 'pending.%',
            ]);
            $stMin = $pdo->prepare('SELECT MIN(id) FROM xabia_licenses WHERE license_key = :k');
            $stMin->execute([':k' => $licenseKey]);
            $minId = (int) $stMin->fetchColumn();
            if ($minId > 0) {
                $wu = $pdo->prepare(
                    'UPDATE xabia_wallets SET installed_domain = :d, installed_site_url = :url WHERE license_id = :id'
                );
                $wu->execute([
                    ':d'   => $claimed,
                    ':url' => str_starts_with(strtolower($claimedUrl), 'http') ? $claimedUrl : 'https://' . $claimed,
                    ':id'  => $minId,
                ]);
            }
            error_log('[xabia-license] bound pending domain key=' . $licenseKey . ' -> ' . $claimed);

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Búsqueda por clave: WHERE license_key = valor enviado (trim), sin traducciones.
     *
     * @return array{id:int,license_key:string,client_domain:string,plan_type:string,expiry_date:?string,status:string,tokens_remaining:int,tokens_used_total:int,wallet_license_id:int}|null
     */
    public static function findActiveWithWalletByKey(string $licenseKey, string $incomingDomainOrUrl = ''): ?array
    {
        $licenseKey = PolarLicenseKey::normalize($licenseKey);
        if ($licenseKey === '') {
            return null;
        }
        $params = [':k' => $licenseKey];
        $whereDomain = '';
        $candidates = self::domainCandidates($incomingDomainOrUrl);
        if ($candidates !== []) {
            $placeholders = [];
            foreach ($candidates as $i => $candidate) {
                $ph = ':d' . $i;
                $placeholders[] = $ph;
                $params[$ph] = $candidate;
            }
            $whereDomain = ' AND l.client_domain IN (' . implode(', ', $placeholders) . ')';
        }
        $sql = 'SELECT l.id, l.license_key, l.client_domain, l.plan_type, l.expiry_date, l.status,
                       (SELECT MIN(lx.id) FROM xabia_licenses lx WHERE lx.license_key = l.license_key) AS wallet_license_id,
                       w.tokens_remaining, w.tokens_used_total
                FROM xabia_licenses l
                INNER JOIN xabia_wallets w ON w.license_id = (SELECT MIN(lx.id) FROM xabia_licenses lx WHERE lx.license_key = l.license_key)
                WHERE l.license_key = :k' . $whereDomain . '
                ORDER BY l.id DESC
                LIMIT 1';
        $st = Db::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['wallet_license_id'] = isset($row['wallet_license_id']) ? (int) $row['wallet_license_id'] : (int) $row['id'];
        $row['tokens_remaining'] = (int) $row['tokens_remaining'];
        $row['tokens_used_total'] = (int) $row['tokens_used_total'];

        return $row;
    }

    /**
     * Todas las filas de licencia con esa clave (puede haber varios client_domain).
     *
     * @return list<array{id:int,license_key:string,client_domain:string,plan_type:string,expiry_date:?string,status:string}>
     */
    public static function findAllByLicenseKey(string $licenseKey): array
    {
        $licenseKey = PolarLicenseKey::normalize($licenseKey);
        if ($licenseKey === '') {
            return [];
        }
        try {
            $st = Db::pdo()->prepare(
                'SELECT id, license_key, client_domain, plan_type, expiry_date, status
                 FROM xabia_licenses
                 WHERE license_key = :k
                 ORDER BY id ASC'
            );
            $st->execute([':k' => $licenseKey]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }

        return is_array($rows) ? $rows : [];
    }

    /**
     * Indica si existe fila en xabia_wallets para el license_id de facturación (MIN(id) por license_key).
     */
    public static function hasWalletForLicenseKey(string $licenseKey): bool
    {
        $licenseKey = PolarLicenseKey::normalize($licenseKey);
        if ($licenseKey === '') {
            return false;
        }
        try {
            $st = Db::pdo()->prepare(
                'SELECT 1 FROM xabia_wallets w
                 WHERE w.license_id = (SELECT MIN(l.id) FROM xabia_licenses l WHERE l.license_key = :k)
                 LIMIT 1'
            );
            $st->execute([':k' => $licenseKey]);

            return $st->fetchColumn() !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function isLicenseRowActive(array $row): bool
    {
        if (($row['status'] ?? '') !== 'active') {
            return false;
        }
        $exp = $row['expiry_date'] ?? null;
        if ($exp !== null && $exp !== '') {
            $ts = strtotime((string) $exp);
            if ($ts !== false && $ts < time()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Consumo Hub (proxy, knowledge, usage): alineado con /license/validate — Core caducado o status distinto de
     * "active" no bloquea si hay fila cartera con tokens_remaining > 0. Solo isSuspended corta antes (en el caller).
     */
    public static function allowsHubTokenConsumption(array $domainLicenseRow, ?array $walletJoinedRow): bool
    {
        if (self::isLicenseRowActive($domainLicenseRow)) {
            return true;
        }
        if ($walletJoinedRow === null) {
            return false;
        }

        return (int) ($walletJoinedRow['tokens_remaining'] ?? 0) > 0;
    }

    public static function domainMatchesLicense(array $licenseRow, string $incomingDomainOrUrl): bool
    {
        return Domain::domainsMatch((string) $licenseRow['client_domain'], $incomingDomainOrUrl);
    }

    /**
     * Activaciones de addon vigentes: status active y sin caducar (expiry_date NULL o fecha >= hoy).
     *
     * @return list<array{addon_slug: string, expiry_date: ?string, created_at: ?string}>
     */
    public static function activeAddonActivationsWithExpiryForLicense(string $licenseKey): array
    {
        $licenseKey = PolarLicenseKey::normalize($licenseKey);
        if ($licenseKey === '') {
            return [];
        }
        try {
            $st = Db::pdo()->prepare(
                "SELECT addon_slug, expiry_date, created_at
                 FROM xabia_addon_activations
                 WHERE license_key = :k
                   AND status = 'active'
                   AND (expiry_date IS NULL OR expiry_date >= CURRENT_DATE)
                 ORDER BY addon_slug ASC, id ASC"
            );
            $st->execute([':k' => $licenseKey]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        if (!is_array($rows) || $rows === []) {
            return [];
        }
        /** @var array<string, array{expiry_date: ?string, created_at: ?string}> $merged */
        $merged = [];
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $slug = trim((string) ($r['addon_slug'] ?? ''));
            if ($slug === '') {
                continue;
            }
            $expRaw = $r['expiry_date'] ?? null;
            $exp = ($expRaw !== null && $expRaw !== '') ? (string) $expRaw : null;
            $creRaw = $r['created_at'] ?? null;
            $cre = ($creRaw !== null && $creRaw !== '') ? (string) $creRaw : null;
            if (!isset($merged[$slug])) {
                $merged[$slug] = ['expiry_date' => $exp, 'created_at' => $cre];

                continue;
            }
            if ($exp === null || $exp === '') {
                $merged[$slug]['expiry_date'] = null;
            } else {
                $prev = $merged[$slug]['expiry_date'];
                if ($prev === null || $prev === '') {
                    $merged[$slug]['expiry_date'] = $exp;
                } else {
                    $tPrev = strtotime((string) $prev);
                    $tNew = strtotime((string) $exp);
                    if ($tPrev !== false && $tNew !== false && $tNew > $tPrev) {
                        $merged[$slug]['expiry_date'] = $exp;
                    }
                }
            }
            if ($cre !== null && $cre !== '') {
                $prevCre = $merged[$slug]['created_at'];
                if ($prevCre === null || $prevCre === '') {
                    $merged[$slug]['created_at'] = $cre;
                } else {
                    $tPrev = strtotime((string) $prevCre);
                    $tNew = strtotime((string) $cre);
                    if ($tPrev !== false && $tNew !== false && $tNew < $tPrev) {
                        $merged[$slug]['created_at'] = $cre;
                    }
                }
            }
        }
        $out = [];
        foreach ($merged as $slug => $data) {
            $out[] = [
                'addon_slug'  => $slug,
                'expiry_date' => $data['expiry_date'],
                'created_at'  => $data['created_at'],
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['addon_slug'], $b['addon_slug']));

        return $out;
    }

    /**
     * Slugs de addons activos y no caducados (misma regla que activeAddonActivationsWithExpiryForLicense).
     *
     * @return list<string>
     */
    public static function activeAddonsForLicense(string $licenseKey): array
    {
        $rows = self::activeAddonActivationsWithExpiryForLicense($licenseKey);

        return array_values(array_map(static fn (array $r): string => $r['addon_slug'], $rows));
    }

    /**
     * @return list<string>
     */
    private static function domainCandidates(string $incomingDomainOrUrl): array
    {
        $host = Domain::normalize($incomingDomainOrUrl);
        if ($host === '') {
            return [];
        }
        $seen = [];
        $out = [];
        $add = static function (string $d) use (&$seen, &$out): void {
            $d = strtolower(trim($d));
            if ($d === '') {
                return;
            }
            if (isset($seen[$d])) {
                return;
            }
            $seen[$d] = true;
            $out[] = $d;
            if (str_starts_with($d, 'www.')) {
                $bare = substr($d, 4);
                if ($bare !== '' && !isset($seen[$bare])) {
                    $seen[$bare] = true;
                    $out[] = $bare;
                }
            } else {
                $www = 'www.' . $d;
                if (!isset($seen[$www])) {
                    $seen[$www] = true;
                    $out[] = $www;
                }
            }
        };

        $parts = explode('.', $host);
        $n = count($parts);
        for ($start = 0; $start < $n; ++$start) {
            $suffix = implode('.', array_slice($parts, $start));
            if ($n - $start < 2) {
                continue;
            }
            $add($suffix);
        }

        return $out;
    }

    /**
     * expiry_date ISO8601 para el plugin (compatible con merge_license_meta_from_api_payload).
     */
    public static function expiryAsIso(?string $dbDatetime): ?string
    {
        if ($dbDatetime === null || $dbDatetime === '') {
            return null;
        }
        $ts = strtotime($dbDatetime);
        if ($ts === false) {
            return null;
        }

        return gmdate('c', $ts);
    }

    public static function isSuspended(array $row): bool
    {
        return (($row['status'] ?? '') === 'suspended');
    }

    public static function webhookDeliveryExists(string $webhookId): bool
    {
        $webhookId = trim($webhookId);
        if ($webhookId === '') {
            return false;
        }
        try {
            $st = Db::pdo()->prepare('SELECT 1 FROM xabia_webhook_deliveries WHERE webhook_id = :i LIMIT 1');
            $st->execute([':i' => $webhookId]);

            return $st->fetchColumn() !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function recordWebhookDelivery(string $webhookId, string $provider = 'polar'): void
    {
        $webhookId = trim($webhookId);
        if ($webhookId === '') {
            return;
        }
        try {
            $st = Db::pdo()->prepare(
                'INSERT IGNORE INTO xabia_webhook_deliveries (webhook_id, provider) VALUES (:i, :p)'
            );
            $st->execute([':i' => $webhookId, ':p' => $provider]);
        } catch (Throwable $e) {
            // Tabla ausente hasta migración: Polar reintentará; mejor registrar en logs del servidor.
        }
    }

    public static function findLicenseKeyByBillingEmail(string $email): ?string
    {
        $keys = self::findDistinctLicenseKeysByBillingEmail($email);

        return $keys[0] ?? null;
    }

    /**
     * Todas las claves distintas asociadas a un email de facturación.
     *
     * @return list<string>
     */
    public static function findDistinctLicenseKeysByBillingEmail(string $email): array
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return [];
        }
        try {
            $st = Db::pdo()->prepare(
                'SELECT DISTINCT license_key FROM xabia_licenses
                 WHERE LOWER(TRIM(billing_email)) = :e
                 ORDER BY license_key ASC'
            );
            $st->execute([':e' => $email]);
            $keys = [];
            while (($v = $st->fetchColumn()) !== false) {
                if (is_string($v) && $v !== '') {
                    $keys[] = $v;
                }
            }

            return $keys;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Licencia activa cuyo client_domain encaja con la URL/host del checkout (addon en sitio existente).
     */
    public static function findLicenseKeyByClientDomain(string $domainOrUrl): ?string
    {
        $claimed = Domain::normalize($domainOrUrl);
        if ($claimed === '' || self::isPendingAssignmentDomain($claimed)) {
            return null;
        }
        try {
            $st = Db::pdo()->query(
                "SELECT license_key, client_domain FROM xabia_licenses
                 WHERE status = 'active' AND client_domain NOT LIKE 'pending.%'
                 ORDER BY id DESC"
            );
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return null;
        }
        if (!is_array($rows)) {
            return null;
        }
        foreach ($rows as $r) {
            $registered = trim((string) ($r['client_domain'] ?? ''));
            if ($registered === '' || !Domain::domainsMatch($registered, $claimed)) {
                continue;
            }
            $key = PolarLicenseKey::normalize((string) ($r['license_key'] ?? ''));

            return $key !== '' ? $key : null;
        }

        return null;
    }

    public static function touchBillingEmailForLicenseKey(string $licenseKey, string $email): void
    {
        $licenseKey = PolarLicenseKey::normalize($licenseKey);
        $email = strtolower(trim($email));
        if ($licenseKey === '' || $email === '') {
            return;
        }
        try {
            $st = Db::pdo()->prepare(
                'UPDATE xabia_licenses SET billing_email = :e WHERE license_key = :k'
            );
            $st->execute([':e' => $email, ':k' => $licenseKey]);
        } catch (Throwable $e) {
        }
    }

    /**
     * Crea fila de licencia + wallet en 0; license_key debe ser la clave Polar (XABIA--…).
     */
    public static function createLicenseWithWalletFromPolar(string $billingEmail, string $clientDomain, string $licenseKey): int
    {
        $billingEmail = strtolower(trim($billingEmail));
        $clientDomain = trim($clientDomain);
        $licenseKey = PolarLicenseKey::normalize($licenseKey);
        if ($billingEmail === '' || $clientDomain === '' || $licenseKey === '') {
            return 0;
        }
        if ($clientDomain === 'pending.invalid' || $clientDomain === '') {
            return 0;
        }
        if (!PolarLicenseKey::isValidFormat($licenseKey)) {
            return 0;
        }
        $pdo = Db::pdo();
        try {
            $st = $pdo->prepare(
                'INSERT INTO xabia_licenses (license_key, client_domain, billing_email, plan_type, expiry_date, status)
                 VALUES (:k, :d, :e, \'standard\', NULL, \'active\')'
            );
            $st->execute([
                ':k' => $licenseKey,
                ':d' => $clientDomain,
                ':e' => $billingEmail,
            ]);
            $id = (int) $pdo->lastInsertId();
            if ($id < 1) {
                return 0;
            }
            self::insertWalletRow($pdo, $id, $clientDomain);

            return $id;
        } catch (Throwable $e) {
            $rows = self::findAllByLicenseKey($licenseKey);
            if ($rows === []) {
                return 0;
            }
            $minId = min(array_map(static fn (array $r): int => (int) ($r['id'] ?? 0), $rows));
            if ($minId < 1) {
                return 0;
            }
            if (!self::hasWalletForLicenseKey($licenseKey)) {
                try {
                    $dom = '';
                    foreach ($rows as $r) {
                        if ((int) ($r['id'] ?? 0) === $minId) {
                            $dom = trim((string) ($r['client_domain'] ?? ''));
                            break;
                        }
                    }
                    self::insertWalletRow($pdo, $minId, $dom);
                } catch (Throwable $e2) {
                }
            }

            return $minId;
        }
    }

    /**
     * Asegura fila (license_key, client_domain) para multi-sitio; reutiliza wallet del MIN(id) por clave.
     */
    public static function ensureLicenseDomainRow(string $licenseKey, string $clientDomain, string $billingEmail = ''): int
    {
        $licenseKey = PolarLicenseKey::normalize($licenseKey);
        $clientDomain = Domain::normalize($clientDomain);
        $billingEmail = strtolower(trim($billingEmail));
        if ($licenseKey === '' || $clientDomain === '' || $clientDomain === 'pending.invalid') {
            return 0;
        }
        $rows = self::findAllByLicenseKey($licenseKey);
        if ($clientDomain === self::PENDING_ASSIGNMENT_DOMAIN) {
            foreach ($rows as $r) {
                if (self::isPendingAssignmentDomain((string) ($r['client_domain'] ?? ''))) {
                    if ($billingEmail !== '') {
                        self::touchBillingEmailForLicenseKey($licenseKey, $billingEmail);
                    }

                    return (int) ($r['id'] ?? 0);
                }
            }
        }
        foreach ($rows as $r) {
            if (Domain::domainsMatch((string) ($r['client_domain'] ?? ''), $clientDomain)) {
                if ($billingEmail !== '') {
                    self::touchBillingEmailForLicenseKey($licenseKey, $billingEmail);
                }

                return (int) ($r['id'] ?? 0);
            }
        }
        $expiry = null;
        $maxTs = 0;
        foreach ($rows as $r) {
            $e = $r['expiry_date'] ?? null;
            if ($e !== null && $e !== '') {
                $t = strtotime((string) $e);
                if ($t !== false && $t > $maxTs) {
                    $maxTs = $t;
                    $expiry = (string) $e;
                }
            }
        }
        if ($rows === []) {
            return self::createLicenseWithWalletFromPolar($billingEmail, $clientDomain, $licenseKey);
        }
        try {
            $pdo = Db::pdo();
            $st = $pdo->prepare(
                'INSERT INTO xabia_licenses (license_key, client_domain, billing_email, plan_type, expiry_date, status)
                 VALUES (:k, :d, :e, \'standard\', :ex, \'active\')'
            );
            $st->execute([
                ':k' => $licenseKey,
                ':d' => $clientDomain,
                ':e' => $billingEmail !== '' ? $billingEmail : null,
                ':ex' => $expiry,
            ]);
            $id = (int) $pdo->lastInsertId();
            if ($id > 0 && $billingEmail !== '') {
                self::touchBillingEmailForLicenseKey($licenseKey, $billingEmail);
            }

            return $id;
        } catch (Throwable $e) {
            $rows = self::findAllByLicenseKey($licenseKey);
            foreach ($rows as $r) {
                if (Domain::domainsMatch((string) ($r['client_domain'] ?? ''), $clientDomain)) {
                    return (int) ($r['id'] ?? 0);
                }
            }

            return 0;
        }
    }

    public static function addTokensToWalletForLicenseKey(string $licenseKey, int $delta): void
    {
        if ($delta === 0) {
            return;
        }
        $licenseKey = PolarLicenseKey::normalize($licenseKey);
        if ($licenseKey === '') {
            return;
        }
        $st = Db::pdo()->prepare(
            'UPDATE xabia_wallets w
             INNER JOIN (
                 SELECT MIN(id) AS mid FROM xabia_licenses WHERE license_key = :k
             ) x ON x.mid = w.license_id
             SET w.tokens_remaining = w.tokens_remaining + :d'
        );
        $st->execute([':k' => $licenseKey, ':d' => $delta]);
    }

    /**
     * Extiende la caducidad del Core: base = max(ahora, max(expiry existente)), luego +N años.
     */
    public static function extendCoreExpiryForLicenseKey(string $licenseKey, int $years = 1): void
    {
        $licenseKey = PolarLicenseKey::normalize($licenseKey);
        if ($licenseKey === '') {
            return;
        }
        if ($years < 1) {
            $years = 1;
        }
        $rows = self::findAllByLicenseKey($licenseKey);
        $maxTs = time();
        foreach ($rows as $r) {
            $e = $r['expiry_date'] ?? null;
            if ($e !== null && $e !== '') {
                $t = strtotime((string) $e);
                if ($t !== false && $t > $maxTs) {
                    $maxTs = $t;
                }
            }
        }
        $newTs = strtotime('+' . $years . ' years', $maxTs);
        if ($newTs === false) {
            return;
        }
        $mysql = gmdate('Y-m-d H:i:s', $newTs);
        try {
            $st = Db::pdo()->prepare('UPDATE xabia_licenses SET expiry_date = :ex, status = \'active\' WHERE license_key = :k');
            $st->execute([':ex' => $mysql, ':k' => $licenseKey]);
        } catch (Throwable $e) {
        }
    }

    public static function setLicenseStatusForLicenseKey(string $licenseKey, string $status): void
    {
        $licenseKey = PolarLicenseKey::normalize($licenseKey);
        $status = trim(strtolower($status));
        if ($licenseKey === '' || !in_array($status, ['active', 'suspended', 'expired'], true)) {
            return;
        }
        try {
            $st = Db::pdo()->prepare('UPDATE xabia_licenses SET status = :s WHERE license_key = :k');
            $st->execute([':s' => $status, ':k' => $licenseKey]);
        } catch (Throwable $e) {
        }
    }

    public static function upsertAddonActivation(
        string $licenseKey,
        string $polarProductId,
        string $addonSlug,
        string $expiryDateYmd,
        string $clientUrl = '',
        string $source = 'polar'
    ): void {
        $licenseKey = PolarLicenseKey::normalize($licenseKey);
        $addonSlug = trim($addonSlug);
        $polarProductId = trim($polarProductId);
        if ($licenseKey === '' || $addonSlug === '') {
            return;
        }
        $pdo = Db::pdo();
        try {
            $st = $pdo->prepare(
                'SELECT id FROM xabia_addon_activations WHERE license_key = :k AND addon_slug = :s LIMIT 1'
            );
            $st->execute([':k' => $licenseKey, ':s' => $addonSlug]);
            $id = $st->fetchColumn();
            if ($id !== false) {
                $u = $pdo->prepare(
                    'UPDATE xabia_addon_activations
                     SET status = \'active\', expiry_date = :ex, product_id = :p, client_url = :u, source = :src
                     WHERE id = :id'
                );
                $u->execute([
                    ':ex'  => $expiryDateYmd,
                    ':p'   => $polarProductId,
                    ':u'   => $clientUrl,
                    ':src' => $source,
                    ':id'  => (int) $id,
                ]);

                return;
            }
            $i = $pdo->prepare(
                'INSERT INTO xabia_addon_activations
                    (license_key, addon_slug, product_id, client_url, status, expiry_date, source)
                 VALUES (:k, :s, :p, :u, \'active\', :ex, :src)'
            );
            $i->execute([
                ':k'   => $licenseKey,
                ':s'   => $addonSlug,
                ':p'   => $polarProductId,
                ':u'   => $clientUrl,
                ':ex'  => $expiryDateYmd,
                ':src' => $source,
            ]);
        } catch (Throwable $e) {
        }
    }

    public static function setAddonActivationStatusForLicense(string $licenseKey, string $addonSlug, string $status): void
    {
        $licenseKey = PolarLicenseKey::normalize($licenseKey);
        $addonSlug = trim($addonSlug);
        $status = trim(strtolower($status));
        if ($licenseKey === '' || $addonSlug === '' || !in_array($status, ['active', 'inactive', 'expired'], true)) {
            return;
        }
        try {
            $st = Db::pdo()->prepare(
                'UPDATE xabia_addon_activations
                 SET status = :s
                 WHERE license_key = :k AND addon_slug = :a'
            );
            $st->execute([
                ':s' => $status,
                ':k' => $licenseKey,
                ':a' => $addonSlug,
            ]);
        } catch (Throwable $e) {
        }
    }

    public static function webhookBusinessEventExists(string $provider, string $eventKey): bool
    {
        $provider = trim(strtolower($provider));
        $eventKey = trim($eventKey);
        if ($provider === '' || $eventKey === '') {
            return false;
        }
        try {
            $st = Db::pdo()->prepare(
                'SELECT 1
                 FROM xabia_webhook_business_events
                 WHERE provider = :p AND event_key = :k
                 LIMIT 1'
            );
            $st->execute([':p' => $provider, ':k' => $eventKey]);

            return $st->fetchColumn() !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function recordWebhookBusinessEvent(string $provider, string $eventKey, string $webhookId = ''): void
    {
        $provider = trim(strtolower($provider));
        $eventKey = trim($eventKey);
        $webhookId = trim($webhookId);
        if ($provider === '' || $eventKey === '') {
            return;
        }
        try {
            $st = Db::pdo()->prepare(
                'INSERT IGNORE INTO xabia_webhook_business_events (provider, event_key, webhook_id)
                 VALUES (:p, :k, :w)'
            );
            $st->execute([':p' => $provider, ':k' => $eventKey, ':w' => $webhookId]);
        } catch (Throwable $e) {
        }
    }

    /**
     * Inserta fila en xabia_wallets; si faltan columnas 013, usa esquema legado.
     */
    private static function insertWalletRow(PDO $pdo, int $licenseId, string $clientDomain): void
    {
        if ($licenseId < 1) {
            return;
        }
        $dom = trim($clientDomain);
        $url = $dom !== ''
            ? substr(preg_match('#^https?://#i', $dom) === 1 ? $dom : ('https://' . $dom), 0, 512)
            : null;
        try {
            $w = $pdo->prepare(
                'INSERT INTO xabia_wallets (license_id, tokens_remaining, tokens_used_total, installed_domain, installed_site_url)
                 VALUES (:id, 0, 0, :dom, :url)'
            );
            $w->execute([
                ':id'  => $licenseId,
                ':dom' => $dom !== '' ? substr($dom, 0, 255) : null,
                ':url' => $url,
            ]);
        } catch (Throwable $e) {
            $w = $pdo->prepare(
                'INSERT INTO xabia_wallets (license_id, tokens_remaining, tokens_used_total) VALUES (:id, 0, 0)'
            );
            $w->execute([':id' => $licenseId]);
        }
    }
}
