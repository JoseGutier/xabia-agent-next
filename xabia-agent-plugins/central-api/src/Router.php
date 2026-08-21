<?php

declare(strict_types=1);

namespace XabiaCentral;

final class Router
{
    /**
     * Resuelve la ruta interna (/xabia/v1/…) ignorando el prefijo público (Slim setBasePath,
     * subcarpeta public/, o URL limpia sin index.php).
     */
    public static function dispatch(): void
    {
        try {
            $path = self::normalizeRoutePath();

            if (self::isLicenseValidatePath($path)) {
                LicenseValidateHandler::handle();

                return;
            }
            if ($path === '/xabia/v1/proxy') {
                ProxyHandler::handle();

                return;
            }
            if ($path === '/xabia/v1/usage') {
                UsageReportHandler::handle();

                return;
            }
            if ($path === '/xabia/v1/webhooks/polar') {
                Handlers\PolarWebhookHandler::handle();

                return;
            }
            if ($path === '/xabia/v1/knowledge/sync' || $path === '/v1/knowledge/sync') {
                KnowledgeSyncHandler::handle();

                return;
            }
            if ($path === '/xabia/v1/knowledge/search' || $path === '/v1/knowledge/search') {
                KnowledgeSearchHandler::handle();

                return;
            }
            if ($path === '/xabia/v1/updates' || $path === '/v1/updates' || $path === '/updates') {
                UpdatesHandler::handle();

                return;
            }
            if (
                $path === '/xabia/v1/i18n/greeting-translate'
                || $path === '/v1/i18n/greeting-translate'
            ) {
                I18nGreetingHandler::handle();

                return;
            }

            Json::respond(404, ['error' => ['message' => 'Not Found', 'path' => $path]]);
        } catch (\Throwable $e) {
            Json::respond(500, [
                'error' => [
                    'message' => 'Error interno del hub',
                    'type'    => 'xabia_hub_exception',
                    'detail'  => Env::str('XABIA_DEBUG') !== '' ? $e->getMessage() : null,
                ],
            ]);
        }
    }

    /**
     * Rutas que disparan la validación de licencia (mismo handler).
     * Canónica: POST/GET /xabia/v1/license/validate
     * Alias: /license/check, /xabia/v1/license/check (compat. clientes que usen path corto).
     *
     * @return list<string>
     */
    private static function licenseValidatePaths(): array
    {
        return [
            '/xabia/v1/license/validate',
            '/xabia/v1/license/check',
            '/license/check',
            '/license/validate',
        ];
    }

    private static function isLicenseValidatePath(string $path): bool
    {
        return in_array($path, self::licenseValidatePaths(), true);
    }

    /**
     * Equivale a ignorar el BasePath del front controller (p. ej. Slim $app->setBasePath('/api-xabia-saas')
     * o /api-xabia-saas/public) y /index.php cuando la URL lo incluye.
     */
    private static function normalizeRoutePath(): string
    {
        $raw = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = is_string($raw) && $raw !== '' ? $raw : '/';
        $path = '/' . trim($path, '/');

        $envBase = Env::str('XABIA_PUBLIC_BASE');
        if ($envBase !== '') {
            $b = rtrim($envBase, '/');
            if ($b !== '' && ($path === $b || str_starts_with($path, $b . '/'))) {
                $path = $path === $b ? '/' : substr($path, strlen($b));
                $path = '/' . trim((string) $path, '/');
            }
        } else {
            $script = $_SERVER['SCRIPT_NAME'] ?? '';
            if ($script !== '' && $script !== '/') {
                $physicalDir = rtrim(str_replace('\\', '/', dirname($script)), '/');
                if ($physicalDir !== '' && $physicalDir !== '.' && ($path === $physicalDir || str_starts_with($path, $physicalDir . '/'))) {
                    $path = $path === $physicalDir ? '/' : substr($path, strlen($physicalDir));
                    $path = '/' . trim((string) $path, '/');
                }
            }
        }

        $prefixes = [
            '/api/public/index.php',
            '/api/public',
            '/api/index.php',
            '/api-xabia-saas/public/index.php',
            '/api-xabia-saas/public',
            '/api-xabia-saas',
            '/api',
        ];
        for ($i = 0; $i < 4; $i++) {
            $changed = false;
            foreach ($prefixes as $prefix) {
                if ($prefix === '') {
                    continue;
                }
                if ($path === $prefix) {
                    $path = '/';
                    $changed = true;

                    break;
                }
                if (str_starts_with($path, $prefix . '/')) {
                    $path = substr($path, strlen($prefix));
                    $path = '/' . trim((string) $path, '/');
                    $changed = true;

                    break;
                }
            }
            if (!$changed) {
                break;
            }
        }

        if (str_starts_with($path, '/index.php')) {
            $path = substr($path, strlen('/index.php')) ?: '/';
            $path = '/' . trim((string) $path, '/');
        } elseif (str_contains($path, '/index.php/')) {
            $pos = strpos($path, '/index.php/');
            if ($pos !== false) {
                $path = substr($path, $pos + strlen('/index.php')) ?: '/';
                $path = '/' . trim((string) $path, '/');
            }
        }

        if ($path === '' || $path === '/') {
            return '/';
        }

        return '/' . trim($path, '/');
    }
}
