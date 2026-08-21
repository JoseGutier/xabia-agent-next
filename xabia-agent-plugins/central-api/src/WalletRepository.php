<?php

declare(strict_types=1);

namespace XabiaCentral;

use PDO;

/**
 * Cartera y log de uso del Hub (SaaS). La tabla `xabia_usage_log` debe ser la del
 * esquema central (license_id, activity_type, tokens_count, …). No usar el modelo
 * del plugin WordPress en la misma tabla física; ver central-api/migrations/ y
 * HUB_DATABASE_ISOLATION.md si WP y el Hub comparten MySQL.
 */
final class WalletRepository
{
    /**
     * Inserta un registro en xabia_usage_log (esquema Hub) sin modificar saldo.
     */
    public static function logUsage(int $licenseId, string $activityType, int $tokens, ?string $sourceUrl, ?string $projectId, ?array $meta): bool
    {
        $pdo = Db::pdo();
        $log = $pdo->prepare(
            'INSERT INTO xabia_usage_log (license_id, activity_type, tokens_count, source_url, project_id, meta_json, `timestamp`)
             VALUES (:lid, :act, :cnt, :src, :pid, :meta, UTC_TIMESTAMP())'
        );

        return $log->execute([
            ':lid'  => $licenseId,
            ':act'  => substr($activityType, 0, 64),
            ':cnt'  => max(0, $tokens),
            ':src'  => $sourceUrl !== null && $sourceUrl !== '' ? substr($sourceUrl, 0, 512) : null,
            ':pid'  => $projectId !== null && $projectId !== '' ? substr($projectId, 0, 191) : null,
            ':meta' => $meta !== null && $meta !== [] ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    /**
     * Descuenta tokens (no negativos en saldo). Devuelve el nuevo tokens_remaining o null si falla.
     */
    public static function deduct(int $licenseId, int $tokens, string $activityType, ?string $sourceUrl, ?string $projectId, ?array $meta): ?int
    {
        if ($tokens < 1) {
            return self::getRemaining($licenseId);
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT tokens_remaining FROM xabia_wallets WHERE license_id = :id FOR UPDATE');
            $st->execute([':id' => $licenseId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                $pdo->rollBack();

                return null;
            }
            $remaining = (int) $row['tokens_remaining'];
            $newRemaining = max(0, $remaining - $tokens);
            $st2 = $pdo->prepare(
                'UPDATE xabia_wallets SET
                    tokens_remaining = :rem,
                    tokens_used_total = tokens_used_total + :used
                 WHERE license_id = :id'
            );
            $st2->execute([
                ':rem'  => $newRemaining,
                ':used' => $tokens,
                ':id'   => $licenseId,
            ]);
            self::touchInstalledSite($licenseId, $sourceUrl);
            self::logUsage($licenseId, $activityType, $tokens, $sourceUrl, $projectId, $meta);
            $pdo->commit();

            return $newRemaining;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function getRemaining(int $licenseId): ?int
    {
        $st = Db::pdo()->prepare('SELECT tokens_remaining FROM xabia_wallets WHERE license_id = :id');
        $st->execute([':id' => $licenseId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return (int) $row['tokens_remaining'];
    }

    /**
     * Guarda en xabia_wallets la web que consume tokens (identificación en BD).
     */
    public static function touchInstalledSite(int $licenseId, ?string $sourceUrl): void
    {
        if ($licenseId < 1) {
            return;
        }
        $raw = $sourceUrl !== null ? trim($sourceUrl) : '';
        if ($raw === '') {
            return;
        }
        $domain = Domain::normalize($raw);
        if ($domain === '') {
            return;
        }
        $displayUrl = preg_match('#^https?://#i', $raw) === 1 ? $raw : ('https://' . $domain);
        $displayUrl = substr($displayUrl, 0, 512);
        $domain = substr($domain, 0, 255);
        try {
            $st = Db::pdo()->prepare(
                'UPDATE xabia_wallets SET
                    installed_site_url = :url,
                    installed_domain = :dom,
                    last_seen_at = UTC_TIMESTAMP()
                 WHERE license_id = :id'
            );
            $st->execute([
                ':url' => $displayUrl,
                ':dom' => $domain,
                ':id'  => $licenseId,
            ]);
        } catch (\Throwable) {
            // Columnas ausentes hasta aplicar migración 013: no bloquear proxy/validate.
        }
    }
}
