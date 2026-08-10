<?php
/**
 * REST bridge for secure wallet recharges from xabia.ai.
 */

if (!defined('ABSPATH')) exit;

class Xabia_Wallet_API {
    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void {
        if (!self::legacy_bridge_enabled()) {
            return;
        }
        register_rest_route('xabia/v1', '/wallet/recharge', [
            'methods' => 'POST',
            'callback' => [self::class, 'handle_recharge'],
            'permission_callback' => '__return_true',
            'args' => [
                'license_key' => ['required' => true],
                'token_amount' => ['required' => true],
                'timestamp' => ['required' => true],
                'signature' => ['required' => true],
            ],
        ]);
    }

    private static function legacy_bridge_enabled(): bool {
        /**
         * Keep disabled by default to enforce centralized top-ups in hub webhook pipeline.
         */
        return (bool) apply_filters('xabia_legacy_wallet_recharge_bridge_enabled', false);
    }

    /**
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public static function handle_recharge($request) {
        $licenseKey = trim((string) $request->get_param('license_key'));
        $tokenAmount = (int) $request->get_param('token_amount');
        $timestamp = trim((string) $request->get_param('timestamp'));
        $signature = trim((string) $request->get_param('signature'));

        if ($licenseKey === '' || $tokenAmount < 1 || $timestamp === '' || $signature === '') {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Missing or invalid recharge parameters.',
            ], 400);
        }

        $configuredLicense = trim((string) get_option('xabia_digixop_license_key', ''));
        if ($configuredLicense === '' || !hash_equals($configuredLicense, $licenseKey)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Forbidden.',
            ], 403);
        }

        $ts = is_numeric($timestamp) ? (int) $timestamp : 0;
        if ($ts < 1 || abs(time() - $ts) > 15 * MINUTE_IN_SECONDS) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Invalid or expired timestamp.',
            ], 403);
        }

        $expected = hash_hmac('sha256', $licenseKey . $tokenAmount . $timestamp, $licenseKey);
        if (!hash_equals($expected, $signature)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Forbidden.',
            ], 403);
        }
        $signatureHash = hash('sha256', $signature);

        global $wpdb;
        Xabia_DB::install_tables();
        $walletTable = Xabia_DB::table('wallets');
        $historyTable = Xabia_DB::table('recharge_history');
        $licenseId = Xabia_DB::wallet_license_id_for_key($licenseKey);
        $licenseHash = hash('sha256', $licenseKey);
        $now = gmdate('Y-m-d H:i:s');
        self::cleanup_old_signature_hashes($historyTable);

        $alreadyProcessed = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $historyTable WHERE signature_hash = %s LIMIT 1",
            $signatureHash
        ));
        if ($alreadyProcessed) {
            $currentBalance = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT tokens_remaining FROM $walletTable WHERE license_id = %s",
                $licenseId
            ));
            return new WP_REST_Response([
                'success' => true,
                'message' => 'Recharge already processed.',
                'license_id' => $licenseId,
                'tokens_added' => 0,
                'new_balance' => $currentBalance,
                'duplicate' => true,
            ], 200);
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT tokens_remaining FROM $walletTable WHERE license_id = %s",
            $licenseId
        ), ARRAY_A);
        if (!is_array($row)) {
            $wpdb->insert($walletTable, [
                'license_id' => $licenseId,
                'license_key_hash' => $licenseHash,
                'tokens_remaining' => 0,
                'tokens_used_total' => 0,
                'updated_at' => $now,
            ]);
        }

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE $walletTable
             SET tokens_remaining = tokens_remaining + %d,
                 license_key_hash = %s,
                 updated_at = %s
             WHERE license_id = %s",
            $tokenAmount,
            $licenseHash,
            $now,
            $licenseId
        ));
        if ($updated === false) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Could not update wallet balance.',
            ], 500);
        }

        $newBalance = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT tokens_remaining FROM $walletTable WHERE license_id = %s",
            $licenseId
        ));
        $source = isset($_SERVER['REMOTE_ADDR']) ? 'api_recharge:' . sanitize_text_field((string) $_SERVER['REMOTE_ADDR']) : 'api_recharge';
        $wpdb->insert($historyTable, [
            'license_id' => $licenseId,
            'license_key_hash' => $licenseHash,
            'amount' => $tokenAmount,
            'balance_after' => $newBalance,
            'source' => $source,
            'signature_hash' => $signatureHash,
            'date' => $now,
        ]);

        if (class_exists('Xabia_Digixop_Client', false) && trim((string) get_option('xabia_digixop_license_key', '')) === $licenseKey) {
            Xabia_Digixop_Client::merge_license_meta_from_api_payload([
                'tokens_remaining' => $newBalance,
            ]);
        }

        return new WP_REST_Response([
            'success' => true,
            'message' => 'Recharge completed.',
            'license_id' => $licenseId,
            'tokens_added' => $tokenAmount,
            'new_balance' => $newBalance,
        ], 200);
    }

    private static function cleanup_old_signature_hashes(string $historyTable): void {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - (30 * DAY_IN_SECONDS));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $historyTable WHERE signature_hash <> '' AND date < %s",
            $cutoff
        ));
    }
}

Xabia_Wallet_API::init();
