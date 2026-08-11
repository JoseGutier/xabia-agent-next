<?php
/**
 * Guardas servidor-side: rechaza acciones PRO en runtime LITE (403 JSON).
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Lite_Guard {

    /** @var list<string> */
    private const PRO_AJAX_ACTIONS = [
        'xabia_ask_ai',
        'xabia_tts',
        'xabia_sync_content',
        'xabia_train_ai',
        'xabia_train_estimate',
        'xabia_test_sql',
        'xabia_test_addon',
        'xabia_sync_brain_cloud',
        'xabia_knowledge_preview',
        'xabia_upload_csv',
        'xabia_central_ingest',
        'xabia_reservas_amelia_services',
        'xabia_purge_orphan_knowledge',
        'xabia_clear_memory',
    ];

    /** @var array<string, string> */
    private const FEATURE_BY_ACTION = [
        'xabia_ask_ai'                   => 'vector_rag',
        'xabia_tts'                      => 'voice_tts',
        'xabia_sync_content'             => 'vector_rag',
        'xabia_train_ai'                 => 'vector_rag',
        'xabia_train_estimate'           => 'vector_rag',
        'xabia_test_sql'                 => 'remote_sql',
        'xabia_test_addon'               => 'addons_woo',
        'xabia_sync_brain_cloud'         => 'xabia_cloud',
        'xabia_knowledge_preview'        => 'pdf_ingest',
        'xabia_upload_csv'               => 'multi_source',
        'xabia_central_ingest'           => 'federation',
        'xabia_reservas_amelia_services' => 'addons_amelia',
        'xabia_purge_orphan_knowledge'   => 'vector_rag',
        'xabia_clear_memory'             => 'vector_rag',
    ];

    public static function init(): void {
        if (!class_exists('Xabia_Features', false) || Xabia_Features::is_pro()) {
            return;
        }

        foreach (self::PRO_AJAX_ACTIONS as $action) {
            add_action('wp_ajax_' . $action, [self::class, 'reject'], 0);
            if (in_array($action, ['xabia_ask_ai', 'xabia_tts', 'xabia_resolve_image'], true)) {
                add_action('wp_ajax_nopriv_' . $action, [self::class, 'reject'], 0);
            }
        }

        add_action('wp_ajax_xabia_resolve_image', [self::class, 'reject'], 0);
        add_action('wp_ajax_nopriv_xabia_resolve_image', [self::class, 'reject'], 0);
    }

    public static function reject(): void {
        $action = isset($_REQUEST['action'])
            ? sanitize_key((string) wp_unslash($_REQUEST['action']))
            : '';
        $feature = self::FEATURE_BY_ACTION[$action] ?? 'pro_only';
        Xabia_Features::reject_pro_json($feature);
    }
}
