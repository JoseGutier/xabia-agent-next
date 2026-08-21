<?php

declare(strict_types=1);

namespace XabiaCentral;

/**
 * Catálogo de versiones publicadas para actualizaciones automáticas (Core + addons).
 * GET /xabia/v1/updates?plugin=xabia-agent-core|xabia-woo|xabia-mec|xabia-avirato
 */
final class UpdatesHandler
{
    public static function handle(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            Json::respond(405, ['error' => ['message' => 'Method Not Allowed', 'type' => 'method']]);

            return;
        }

        $plugin = UpdateCatalog::normalizeSlug((string) ($_GET['plugin'] ?? 'xabia-agent-core'));
        if ($plugin === '') {
            Json::respond(400, ['error' => ['message' => 'Plugin slug inválido', 'type' => 'invalid_plugin']]);

            return;
        }

        $meta = UpdateCatalog::resolve($plugin);
        if ($meta === null) {
            Json::respond(404, [
                'error' => [
                    'message' => 'Plugin no encontrado en el catálogo de updates o versión no configurada en el Hub',
                    'type'    => 'not_found',
                ],
            ]);

            return;
        }

        Json::respond(200, $meta);
    }
}
