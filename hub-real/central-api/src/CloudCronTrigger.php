<?php

declare(strict_types=1);

namespace XabiaCentral;

/**
 * Reloj Maestro: dispara POST firmado a /wp-json/xabia/v1/cloud-cron-trigger en cada sitio activo.
 */
final class CloudCronTrigger
{
    private const ENDPOINT_PATH = '/wp-json/xabia/v1/cloud-cron-trigger';
    private const HTTP_TIMEOUT = 45;

    /**
     * @param list<string> $argv
     */
    public static function runFromCli(array $argv): int
    {
        $dryRun = self::cliFlag($argv, '--dry-run') || Env::str('XABIA_CLOUD_CRON_DRY_RUN') === '1';
        $limit = self::cliInt($argv, '--limit', (int) (Env::str('XABIA_CLOUD_CRON_LIMIT') ?: '500'));
        $sleepMs = self::cliInt($argv, '--sleep-ms', (int) (Env::str('XABIA_CLOUD_CRON_SLEEP_MS') ?: '250'));

        $sites = CloudCronSitesRepository::listTriggerableSites($limit);
        $summary = [
            'ok'       => true,
            'dry_run'  => $dryRun,
            'total'    => count($sites),
            'success'  => 0,
            'failed'   => 0,
            'skipped'  => 0,
            'results'  => [],
        ];

        foreach ($sites as $site) {
            $result = self::triggerSite($site, $dryRun);
            $summary['results'][] = $result;
            if (!empty($result['skipped'])) {
                ++$summary['skipped'];
            } elseif (!empty($result['ok'])) {
                ++$summary['success'];
            } else {
                ++$summary['failed'];
                $summary['ok'] = false;
            }
            if (!$dryRun && $sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $json = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (is_string($json)) {
            fwrite(STDOUT, $json . "\n");
        }

        return $summary['failed'] > 0 ? 1 : 0;
    }

    /**
     * @param array{license_id: int, license_key: string, installed_site_url: string, installed_domain: string} $site
     * @return array{license_id: int, url: string, ok: bool, http_code: int, skipped: bool, message: string}
     */
    public static function triggerSite(array $site, bool $dryRun = false): array
    {
        $licenseId = (int) ($site['license_id'] ?? 0);
        $licenseKey = trim((string) ($site['license_key'] ?? ''));
        $sourceUrl = trim((string) ($site['installed_site_url'] ?? ''));
        $targetUrl = self::buildTriggerUrl($sourceUrl);

        $base = [
            'license_id' => $licenseId,
            'url'        => $targetUrl,
            'skipped'    => false,
        ];

        if ($licenseKey === '' || $sourceUrl === '' || $targetUrl === '') {
            return $base + [
                'ok'         => false,
                'http_code'  => 0,
                'message'    => 'Sitio sin URL o licencia válida',
            ];
        }

        if ($dryRun) {
            return $base + [
                'ok'        => true,
                'http_code' => 0,
                'message'   => 'dry-run: no se envió la petición',
            ];
        }

        $body = json_encode(['trigger' => 'hub_master_clock', 'ts' => time()], JSON_UNESCAPED_UNICODE);
        if (!is_string($body)) {
            $body = '{}';
        }

        $headers = HubClientSignature::sign($body, $licenseKey, $sourceUrl);
        $headers['Content-Type'] = 'application/json';
        $headers['Accept'] = 'application/json';

        $response = self::httpPost($targetUrl, $body, $headers);
        $ok = $response['http_code'] >= 200 && $response['http_code'] < 300;

        return $base + [
            'ok'        => $ok,
            'http_code' => $response['http_code'],
            'message'   => $ok
                ? 'trigger aceptado'
                : substr(trim($response['body']), 0, 300),
        ];
    }

    public static function buildTriggerUrl(string $installedSiteUrl): string
    {
        $base = trim($installedSiteUrl);
        if ($base === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $base)) {
            $base = 'https://' . $base;
        }
        $base = rtrim($base, '/');

        return $base . self::ENDPOINT_PATH;
    }

    /**
     * @param array<string, string> $headers
     * @return array{http_code: int, body: string}
     */
    private static function httpPost(string $url, string $body, array $headers): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                return ['http_code' => 0, 'body' => 'curl_init failed'];
            }
            $flat = [];
            foreach ($headers as $k => $v) {
                $flat[] = $k . ': ' . $v;
            }
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $flat,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::HTTP_TIMEOUT,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_USERAGENT      => 'XabiaHubCloudCron/1.0',
            ]);
            $respBody = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return [
                'http_code' => $code,
                'body'      => is_string($respBody) ? $respBody : '',
            ];
        }

        $ctxHeaders = "Content-Type: application/json\r\nAccept: application/json\r\n";
        foreach ($headers as $k => $v) {
            $ctxHeaders .= $k . ': ' . $v . "\r\n";
        }
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => $ctxHeaders,
                'content' => $body,
                'timeout' => self::HTTP_TIMEOUT,
            ],
        ]);
        $respBody = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', (string) $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }

        return [
            'http_code' => $code,
            'body'      => is_string($respBody) ? $respBody : '',
        ];
    }

    /**
     * @param list<string> $argv
     */
    private static function cliFlag(array $argv, string $flag): bool
    {
        return in_array($flag, $argv, true);
    }

    /**
     * @param list<string> $argv
     */
    private static function cliInt(array $argv, string $flag, int $default): int
    {
        $n = count($argv);
        for ($i = 0; $i < $n - 1; ++$i) {
            if ($argv[$i] === $flag && is_numeric($argv[$i + 1])) {
                return max(0, (int) $argv[$i + 1]);
            }
        }

        return $default;
    }
}
