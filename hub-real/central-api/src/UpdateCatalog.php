<?php

declare(strict_types=1);

namespace XabiaCentral;

/**
 * Catálogo de plugins publicados en GET /xabia/v1/updates (Core + addons).
 */
final class UpdateCatalog
{
    /**
     * @return array<string, array{slug: string, name: string, env_prefix: string, homepage: string}>
     */
    public static function definitions(): array
    {
        return [
            'xabia-agent-core' => [
                'slug'       => 'xabia-agent-core',
                'name'       => 'Xabia Agent Core',
                'env_prefix' => 'XABIA_CORE',
                'homepage'   => 'https://xabia.ai/docs/',
            ],
            'xabia-woo' => [
                'slug'       => 'xabia-woo',
                'name'       => 'Xabia Woo',
                'env_prefix' => 'XABIA_WOO',
                'homepage'   => 'https://xabia.ai/docs/manual-usuario-xabia-woo.pdf',
            ],
            'xabia-mec' => [
                'slug'       => 'xabia-mec',
                'name'       => 'Xabia MEC',
                'env_prefix' => 'XABIA_MEC',
                'homepage'   => 'https://xabia.ai/docs/manual-usuario-xabia-mec.pdf',
            ],
            'xabia-avirato' => [
                'slug'       => 'xabia-avirato',
                'name'       => 'Xabia Avirato',
                'env_prefix' => 'XABIA_AVIRATO',
                'homepage'   => 'https://xabia.ai/docs/manual-usuario-xabia-avirato.pdf',
            ],
        ];
    }

    /**
     * @return array{
     *   slug: string,
     *   name: string,
     *   version: string,
     *   requires: string,
     *   requires_php: string,
     *   tested: string,
     *   package: string,
     *   download_url: string,
     *   homepage: string,
     *   last_updated: string
     * }|null
     */
    public static function resolve(string $slug): ?array
    {
        $slug = self::normalizeSlug($slug);
        if ($slug === '') {
            return null;
        }
        $defs = self::definitions();
        if (!isset($defs[$slug])) {
            return null;
        }
        $def = $defs[$slug];
        $prefix = (string) $def['env_prefix'];

        $version = self::sanitizeVersion(Env::str("{$prefix}_LATEST_VERSION", ''));
        if ($version === '') {
            return null;
        }

        $packageDefault = 'https://xabia.ai/downloads/' . $slug . '-' . $version . '.zip';
        $package = self::sanitizeHttpsUrl(Env::str("{$prefix}_UPDATE_PACKAGE", $packageDefault));
        if ($package === '') {
            return null;
        }

        $requires = self::sanitizeVersion(Env::str("{$prefix}_REQUIRES_WP", Env::str('XABIA_CORE_REQUIRES_WP', '6.0'))) ?: '6.0';
        $requiresPhp = self::sanitizeVersion(Env::str("{$prefix}_REQUIRES_PHP", Env::str('XABIA_CORE_REQUIRES_PHP', '7.4'))) ?: '7.4';
        $tested = self::sanitizeVersion(Env::str("{$prefix}_TESTED_WP", Env::str('XABIA_CORE_TESTED_WP', '6.7'))) ?: '6.7';
        $homepage = self::sanitizeHttpsUrl(Env::str("{$prefix}_UPDATE_HOMEPAGE", (string) $def['homepage']))
            ?: self::sanitizeHttpsUrl((string) $def['homepage'])
            ?: 'https://xabia.ai/docs/';
        $lastUpdated = self::sanitizeDate(Env::str("{$prefix}_UPDATE_DATE", Env::str('XABIA_CORE_UPDATE_DATE', gmdate('Y-m-d'))));

        return [
            'slug'         => $slug,
            'name'         => (string) $def['name'],
            'version'      => $version,
            'requires'     => $requires,
            'requires_php' => $requiresPhp,
            'tested'       => $tested,
            'package'      => $package,
            'download_url' => $package,
            'homepage'     => $homepage,
            'last_updated' => $lastUpdated,
        ];
    }

    public static function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9\-]/', '', $slug) ?? '';

        return substr($slug, 0, 64);
    }

    private static function sanitizeVersion(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^\d+(?:\.\d+)*$/', $raw) === 1) {
            return $raw;
        }

        return '';
    }

    private static function sanitizeHttpsUrl(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || !filter_var($raw, FILTER_VALIDATE_URL)) {
            return '';
        }
        if (!preg_match('#^https://#i', $raw)) {
            return '';
        }

        return $raw;
    }

    private static function sanitizeDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            return $raw;
        }

        return gmdate('Y-m-d');
    }
}
