<?php
if (!defined('ABSPATH')) exit;

/**
 * Aplica el preset de la app activa
 */
function xabia_apply_app_preset(array $params): void {

    $app = $params['app'] ?? null;
    if (!$app) return;

    $app = sanitize_key($app);

    $preset_file = __DIR__ . "/{$app}/preset.php";

    if (!file_exists($preset_file)) {
        error_log("[Xabia] ⚠️ App preset no encontrado: {$app}");
        return;
    }

    require_once $preset_file;

    $fn = "xabia_app_{$app}_apply_context_preset";

    if (function_exists($fn)) {
        $fn($params);
        error_log("[Xabia] ✅ Preset aplicado: {$app}");
    } else {
        error_log("[Xabia] ❌ Función de preset no encontrada: {$fn}");
    }
}