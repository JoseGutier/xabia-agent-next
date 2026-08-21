<?php

declare(strict_types=1);

namespace XabiaCentral;

/**
 * Mapeo Polar product.id → lógica interna del hub.
 *
 * Añade entradas reales (claves = IDs `prod_…` del dashboard de Polar).
 * Los UUID de xabia-woo son marcadores: cámbialos por los del producto al publicarlo en Polar.
 *
 * @phpstan-type TokenEntry array{type: 'tokens', amount: int}
 * @phpstan-type AddonEntry array{type: 'addon', addon_slug: string}
 * @phpstan-type CoreEntry array{type: 'core', extend_years?: int}
 */
final class PolarProductMap
{
    /**
     * @var array<string, array{type: string, amount?: int, addon_slug?: string, extend_years?: int}>
     */
    private const ENTRIES = [
        // --- PACKS DE TOKENS ---
        'a6e9f15a-f4ac-4bd4-b6f9-c3d9f69ce53e'    => ['type' => 'tokens', 'amount' => 5000000],   // 29€ Starter
        '842d765b-a6cd-4be9-8058-d29a23029884'   => ['type' => 'tokens', 'amount' => 20000000],  // 79€ Business
        '040d72f1-e302-4f79-81bd-a97987d74635' => ['type' => 'tokens', 'amount' => 100000000], // 249€ Enterprise

        // --- LICENCIAS CORE ---
        '80a7bbd7-6d9f-41c4-b6a6-ad9e181cd991'       => ['type' => 'core', 'extend_years' => 1],    // 199€
        '4db9de15-39e7-4d5b-814f-be8e334d874e' => ['type' => 'core', 'extend_years' => 1],    // 69€/año

        // --- ADDONS / PLUGINS ---
        '98a1013f-0439-4428-a1af-0e064d9a352d'   => ['type' => 'addon', 'addon_slug' => 'xabia-avirato'], // 49€/año
        '8078756b-c566-4557-a55d-3712d8e47c44'  => ['type' => 'addon', 'addon_slug' => 'xabia-mec'], // MEC 49€/año
        // Woo (Polar producción Digixop)
        '50531883-49dc-486e-ba21-3bdb998d455e'  => ['type' => 'addon', 'addon_slug' => 'xabia-woo'],
        'c1f8a2b0-7e3d-4c9a-8b1e-0d2f3a4b5c6d'  => ['type' => 'addon', 'addon_slug' => 'xabia-woo'],
    ];

    /**
     * @return array{type: string, amount?: int, addon_slug?: string, extend_years?: int}|null
     */
    /**
     * UUID normalizado (sin prefijo prod_, minúsculas).
     */
    public static function normalizeProductUuid(string $raw): string
    {
        $id = strtolower(trim($raw));
        if ($id === '') {
            return '';
        }
        if (str_starts_with($id, 'prod_')) {
            $id = substr($id, 5);
        }

        return $id;
    }

    /**
     * Mapa estático + overrides desde .env del hub (POLAR_PRODUCT_UUID_*).
     *
     * @return array<string, array{type: string, amount?: int, addon_slug?: string, extend_years?: int}>
     */
    private static function entries(): array
    {
        $entries = self::ENTRIES;
        $envAddons = [
            'POLAR_PRODUCT_UUID_MEC'     => 'xabia-mec',
            'POLAR_PRODUCT_UUID_WOO'     => 'xabia-woo',
            'POLAR_PRODUCT_UUID_AVIRATO' => 'xabia-avirato',
        ];
        foreach ($envAddons as $envKey => $addonSlug) {
            $uuid = self::normalizeProductUuid(Env::str($envKey));
            if ($uuid !== '') {
                $entries[$uuid] = ['type' => 'addon', 'addon_slug' => $addonSlug];
            }
        }

        return $entries;
    }

    public static function resolve(string $polarProductId): ?array
    {
        $id = self::normalizeProductUuid($polarProductId);
        if ($id === '') {
            return null;
        }
        $entries = self::entries();
        if (!isset($entries[$id])) {
            return null;
        }
        $e = $entries[$id];
        if (!is_array($e) || !isset($e['type']) || !is_string($e['type'])) {
            return null;
        }

        return $e;
    }
}