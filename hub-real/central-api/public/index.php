<?php

declare(strict_types=1);

/**
 * Punto de entrada HTTP del hub Xabia (docroot: esta carpeta `public/`).
 *
 * El Router normaliza la ruta quitando automáticamente prefijos habituales:
 *   - URL limpia (recomendada): /api-xabia-saas/xabia/v1/… (sin /public/index.php/)
 *   - Legacy: /api-xabia-saas/public/index.php/xabia/v1/…
 *
 * Equivale a Slim $app->setBasePath('/api-xabia-saas') o '/api-xabia-saas/public' según el vhost.
 * Opcional: variable `XABIA_PUBLIC_BASE` (entorno o central-api/.env) para un prefijo distinto.
 *
 * Rutas internas tras normalizar (siempre incluir prefijo /xabia/v1 salvo alias):
 *   POST|GET /xabia/v1/license/validate  (canónica; misma lógica: /license/check, /xabia/v1/license/check)
 *   POST /xabia/v1/proxy
 *   POST /xabia/v1/usage
 *
 * URL pública típica (sin index.php): https://xabia.ai/api/xabia/v1/license/validate
 * Con .htaccess en el directorio padre de public/: ver deploy/api/.htaccess
 */
require dirname(__DIR__) . '/bootstrap.php';

XabiaCentral\Router::dispatch();
