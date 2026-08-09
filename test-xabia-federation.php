<?php
/**
 * Prueba de federación Xabia Central (PUSH paralelo + PULL simulado + verificación).
 *
 * Uso (solo CLI recomendado):
 *   cd /ruta/a/wordpress && php wp-content/plugins/xabia-agent-next/test-xabia-federation.php
 *
 * O definir en wp-config.php: define('XABIA_ALLOW_FEDERATION_TEST', true);
 * y abrir en navegador (solo si entiendes el riesgo en producción).
 */

if (!defined('ABSPATH')) {
    $wp = dirname(__FILE__, 4) . '/wp-load.php';
    if (!is_readable($wp)) {
        $wp = dirname(__FILE__, 3) . '/wp-load.php';
    }
    if (!is_readable($wp)) {
        die("No se encontró wp-load.php. Ejecuta desde la instalación WordPress o ajusta la ruta.\n");
    }
    require_once $wp;
}

if (php_sapi_name() !== 'cli' && (!defined('XABIA_ALLOW_FEDERATION_TEST') || !XABIA_ALLOW_FEDERATION_TEST)) {
    header('HTTP/1.1 403 Forbidden');
    die('Ejecutar solo por CLI o con XABIA_ALLOW_FEDERATION_TEST en wp-config.php.');
}

if (!function_exists('wp_json_encode')) {
    die('WordPress no está cargado correctamente.');
}

define('XABIA_FED_TEST_PROJECT', 'xabia_test_federation');

/** URLs ficticias interceptadas por pre_http_request */
define('XABIA_FED_TEST_URL_A', 'https://xabia-fed-test.invalid/static-a.json');
define('XABIA_FED_TEST_URL_B', 'https://xabia-fed-test.invalid/static-b.json');

require_once dirname(__FILE__) . '/integrations/central/class-xabia-central-setup.php';
require_once dirname(__FILE__) . '/integrations/central/class-xabia-central.php';
require_once dirname(__FILE__) . '/integrations/central/class-xabia-central-sync.php';
require_once dirname(__FILE__) . '/integrations/central/class-xabia-central-ingest.php';

Xabia_Central_Setup::install();

/**
 * POST paralelos (curl_multi si existe; si no, secuencial).
 *
 * @param array<string, array{url: string, body: string, headers: string[]}> $jobs
 * @return array<string, array{code: int, body: string, error?: string}>
 */
function xabia_federation_test_parallel_post(array $jobs) {
    $out = [];
    if (!function_exists('curl_multi_init')) {
        foreach ($jobs as $id => $job) {
            $r = wp_remote_post(
                $job['url'],
                [
                    'timeout' => 30,
                    'headers' => $job['headers'],
                    'body'    => $job['body'],
                ]
            );
            if (is_wp_error($r)) {
                $out[$id] = ['code' => 0, 'body' => '', 'error' => $r->get_error_message()];
            } else {
                $out[$id] = [
                    'code' => (int) wp_remote_retrieve_response_code($r),
                    'body' => (string) wp_remote_retrieve_body($r),
                ];
            }
        }
        return $out;
    }

    $mh = curl_multi_init();
    $chs = [];
    foreach ($jobs as $id => $job) {
        $ch = curl_init($job['url']);
        $headers = $job['headers'];
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $job['body'],
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_multi_add_handle($mh, $ch);
        $chs[$id] = $ch;
    }
    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running && $status === CURLM_OK);

    foreach ($chs as $id => $ch) {
        $body = (string) curl_multi_getcontent($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        $out[$id] = $err !== ''
            ? ['code' => $code, 'body' => $body, 'error' => $err]
            : ['code' => $code, 'body' => $body];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

$errors = 0;

if (php_sapi_name() !== 'cli' && !headers_sent()) {
    header('Content-Type: text/plain; charset=utf-8');
}

echo "=== Xabia Federation Test ===\n\n";

global $wpdb;
$table_nodes = class_exists('Xabia_DB', false) ? Xabia_DB::table('federation_nodes') : $wpdb->prefix . 'xabia_federation_nodes';
$table_kv    = class_exists('Xabia_DB', false) ? Xabia_DB::table('knowledge_vectors') : $wpdb->prefix . 'xabia_knowledge_vectors';

/** Limpieza proyecto de prueba anterior */
$wpdb->delete($table_kv, ['project_id' => XABIA_FED_TEST_PROJECT], ['%s']);
$wpdb->delete($table_nodes, ['project_id' => XABIA_FED_TEST_PROJECT], ['%s']);

$projects = get_option('xabia_projects_config', []);
if (!is_array($projects)) {
    $projects = [];
}
$projects[XABIA_FED_TEST_PROJECT] = [
    'name'         => 'Test Federación (script)',
    'source_type'  => 'addon',
    'addon_slug'   => 'xabia_central',
    'rules'        => ['instructions' => 'Test'],
    'design'       => [],
];
update_option('xabia_projects_config', $projects);

$push_specs = [
    'farmacia-1' => [
        'name'    => 'Farmacia 1',
        'api_key' => 'xabia_test_key_farmacia_1_' . wp_generate_password(12, false, false),
    ],
    'museo-b'    => [
        'name'    => 'Museo B',
        'api_key' => 'xabia_test_key_museo_b_' . wp_generate_password(12, false, false),
    ],
    'bus-local'  => [
        'name'    => 'Bus Local',
        'api_key' => 'xabia_test_key_bus_' . wp_generate_password(12, false, false),
    ],
];

foreach ($push_specs as $nid => $spec) {
    Xabia_Central_Nodes::save([
        'project_id' => XABIA_FED_TEST_PROJECT,
        'node_id'    => $nid,
        'name'       => $spec['name'],
        'type'       => 'push',
        'config'     => ['mapping' => []],
        'api_key'    => $spec['api_key'],
    ]);
}

$pull_records_a = [];
$pull_records_b = [];
for ($i = 1; $i <= 10; $i++) {
    $pull_records_a[] = [
        'ente_id'       => 'pull-a-item-' . $i,
        'content_chunk' => 'Contenido estático A línea ' . $i,
    ];
    $pull_records_b[] = [
        'ente_id'       => 'pull-b-item-' . $i,
        'content_chunk' => 'Contenido estático B línea ' . $i,
    ];
}

add_filter(
    'pre_http_request',
    static function ($preempt, $args, $url) use ($pull_records_a, $pull_records_b) {
        if (strpos($url, XABIA_FED_TEST_URL_A) !== false) {
            return [
                'headers'  => [],
                'body'     => wp_json_encode(['records' => $pull_records_a], JSON_UNESCAPED_UNICODE),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
                'filename' => null,
            ];
        }
        if (strpos($url, XABIA_FED_TEST_URL_B) !== false) {
            return [
                'headers'  => [],
                'body'     => wp_json_encode(['records' => $pull_records_b], JSON_UNESCAPED_UNICODE),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
                'filename' => null,
            ];
        }
        return $preempt;
    },
    10,
    3
);

Xabia_Central_Nodes::save([
    'project_id' => XABIA_FED_TEST_PROJECT,
    'node_id'    => 'pull-static-a',
    'name'       => 'Pull Estático A',
    'type'       => 'pull',
    'config'     => [
        'endpoint_url' => XABIA_FED_TEST_URL_A,
        'format'       => 'json',
        'mapping'      => [],
    ],
]);

Xabia_Central_Nodes::save([
    'project_id' => XABIA_FED_TEST_PROJECT,
    'node_id'    => 'pull-static-b',
    'name'       => 'Pull Estático B',
    'type'       => 'pull',
    'config'     => [
        'endpoint_url' => XABIA_FED_TEST_URL_B,
        'format'       => 'json',
        'mapping'      => [],
    ],
]);

$ingest_url = admin_url('admin-ajax.php?action=xabia_central_ingest');
$jobs       = [];

foreach ($push_specs as $nid => $spec) {
    $records = [];
    for ($i = 1; $i <= 10; $i++) {
        $records[] = [
            'ente_id'       => $nid . '-record-' . $i,
            'content_chunk' => 'Dato de prueba ' . $spec['name'] . ' #' . $i,
        ];
    }
    $payload = [
        'node_id'    => $nid,
        'project_id' => XABIA_FED_TEST_PROJECT,
        'api_key'    => $spec['api_key'],
        'records'    => $records,
    ];
    $jobs['push:' . $nid] = [
        'url'     => $ingest_url,
        'body'    => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
        'headers' => ['Content-Type: application/json; charset=utf-8'],
    ];
}

echo "--- PUSH paralelo (3 nodos x 10 registros) ---\n";
$responses = xabia_federation_test_parallel_post($jobs);

$push_ok = 0;
foreach ($responses as $label => $resp) {
    $code = $resp['code'] ?? 0;
    $body = $resp['body'] ?? '';
    $err  = $resp['error'] ?? '';
    $json = json_decode($body, true);
    $ok   = $code >= 200 && $code < 300 && is_array($json) && !empty($json['success']);
    if ($ok) {
        $push_ok++;
        echo "{$label}: HTTP {$code} — " . ($json['data']['message'] ?? 'OK') . "\n";
    } else {
        $errors++;
        echo "{$label}: FALLO HTTP {$code}" . ($err !== '' ? " curl: {$err}" : '') . " — " . substr($body, 0, 200) . "\n";
    }
}

echo "\n--- PULL (2 nodos JSON simulado) ---\n";
$pull_count = 0;
$pull_nodes_done = 0;
if (class_exists('Xabia_Central_Sync', false)) {
    $pull_count = (int) Xabia_Central_Sync::sync_project(XABIA_FED_TEST_PROJECT);
    echo "sync_project registros insertados/actualizados: {$pull_count}\n";
    if ($pull_count < 20) {
        $errors++;
        echo "Advertencia: se esperaban 20 filas de pull (10+10).\n";
    }
    $pull_nodes_done = $pull_count >= 20 ? 2 : ($pull_count >= 10 ? 1 : ($pull_count > 0 ? 1 : 0));
} else {
    $errors++;
    echo "ERROR: Xabia_Central_Sync no disponible.\n";
}

$nodes_ok = $push_ok + $pull_nodes_done;

echo "\n--- Verificación en {$table_kv} ---\n";

$rows = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT id, federation_node_id, ente_id, content_chunk FROM {$table_kv} WHERE project_id = %s ORDER BY federation_node_id, ente_id",
        XABIA_FED_TEST_PROJECT
    ),
    ARRAY_A
);

$node_names = [];
foreach (Xabia_Central_Nodes::get_for_project(XABIA_FED_TEST_PROJECT) as $n) {
    $node_names[$n['node_id']] = $n['name'];
}

$attr_errors = 0;
foreach ($rows as $r) {
    $nid   = (string) ($r['federation_node_id'] ?? '');
    $name  = $node_names[$nid] ?? '';
    $chunk = (string) ($r['content_chunk'] ?? '');
    $pref  = 'Fuente: ' . $name . ' | ';
    $len   = strlen($pref);
    if ($name !== '' && $len > 0 && (strlen($chunk) < $len || substr($chunk, 0, $len) !== $pref)) {
        $attr_errors++;
        echo "Atribución incorrecta id={$r['id']} nodo={$nid}: " . substr($chunk, 0, 80) . "…\n";
    }
}
if ($attr_errors === 0) {
    echo "Todas las filas comienzan con el prefijo esperado \"Fuente: [Nombre del nodo] | \".\n";
} else {
    $errors += $attr_errors;
}

$dups = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT federation_node_id, ente_id, COUNT(*) AS c FROM {$table_kv}
         WHERE project_id = %s AND federation_node_id IS NOT NULL AND federation_node_id <> ''
         GROUP BY federation_node_id, ente_id HAVING c > 1",
        XABIA_FED_TEST_PROJECT
    ),
    ARRAY_A
);

if (!empty($dups)) {
    foreach ($dups as $d) {
        echo "DUPLICADO: nodo {$d['federation_node_id']} ente {$d['ente_id']} x{$d['c']}\n";
    }
    $errors += count($dups);
} else {
    echo "Sin duplicados (project_id + federation_node_id + ente_id).\n";
}

$total_rows = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table_kv} WHERE project_id = %s",
        XABIA_FED_TEST_PROJECT
    )
);

echo "\n=== Reporte final ===\n";
echo 'Nodos procesados: ' . (int) $nodes_ok . ' | Registros totales: ' . (int) $total_rows . ' | Errores: ' . (int) $errors . "\n";

if (php_sapi_name() === 'cli') {
    exit($errors > 0 ? 1 : 0);
}
