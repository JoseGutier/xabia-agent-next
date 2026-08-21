<?php

declare(strict_types=1);

namespace XabiaCentral;

/**
 * Slug canónico 1:1 por empresa (guiones medios; sin IDs WP ni alias con guión bajo).
 */
final class KnowledgeSlug
{
    public static function canonical(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (strcasecmp($raw, 'global') === 0) {
            return 'global';
        }
        $slug = strtolower(str_replace(['_', ' '], '-', $raw));
        $slug = preg_replace('/-+/', '-', $slug) ?? $slug;
        $slug = trim($slug, '-');
        if ($slug === '') {
            return '';
        }

        return substr($slug, 0, 100);
    }

    /**
     * @param array{ente_id?:string,source_record_id?:string,meta_json?:?string} $row
     * @return array{ente_id:string,source_record_id:string}
     */
    public static function resolveIdentity(array $row): array
    {
        $ente = self::canonical((string) ($row['ente_id'] ?? ''));
        $source = self::canonical((string) ($row['source_record_id'] ?? ''));

        if ($ente === '' || $ente === 'global') {
            $metaJson = $row['meta_json'] ?? null;
            if (is_string($metaJson) && $metaJson !== '') {
                $meta = json_decode($metaJson, true);
                if (is_array($meta)) {
                    foreach (['__canonical_key', '__ente_id', '__source_record_id', 'Slug_Empresa', 'post_name', 'slug'] as $key) {
                        if (empty($meta[$key])) {
                            continue;
                        }
                        $candidate = self::canonical((string) $meta[$key]);
                        if ($candidate !== '' && $candidate !== 'global') {
                            $ente = $candidate;
                            break;
                        }
                    }
                }
            }
        }

        if ($ente !== '' && $ente !== 'global') {
            return ['ente_id' => $ente, 'source_record_id' => $ente];
        }

        if ($source !== '' && $source !== 'global' && !ctype_digit($source)) {
            return ['ente_id' => $source, 'source_record_id' => $source];
        }

        return [
            'ente_id'          => $ente !== '' ? $ente : 'global',
            'source_record_id' => $source !== '' ? $source : '',
        ];
    }
}
