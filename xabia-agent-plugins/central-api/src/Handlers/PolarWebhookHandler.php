<?php

declare(strict_types=1);

namespace XabiaCentral\Handlers;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use XabiaCentral\Domain;
use XabiaCentral\Env;
use XabiaCentral\Json;
use XabiaCentral\LicenseRepository;
use XabiaCentral\PolarLicenseKey;
use XabiaCentral\PolarProductMap;
use XabiaCentral\StandardWebhooks;

/**
 * Webhook Polar (Standard Webhooks).
 * La licencia en xabia_licenses.license_key es el identificador que envía Polar (p. ej. XABIA--…); no se generan claves locales.
 *
 * Prioridad metadata (1:1 DTP): si existe product_type = initial | renewal | topup (checkout/order o línea de pedido),
 * esa tipología manda sobre el significado inferido solo por UUID.
 * Sin product_type: se usa PolarProductMap por prod UUID (comportamiento histórico).
 *
 * - order.paid / order.updated (pagado): packs, core one-shot, addons.
 * - subscription.created / subscription.updated / subscription.active: core y addons recurrentes.
 * - benefit_grant.created / updated: clave en payload de beneficio (Polar Licenses).
 */
final class PolarWebhookHandler
{
    /** Tokens de bienvenida / renovación Core (product_type=initial|renewal o compra core en PolarProductMap). */
    private const CORE_WELCOME_TOKENS = 10000000;

    private const FULFILL_SKIP_NONE = '';
    private const FULFILL_SKIP_LICENSE_UNRESOLVED = 'license_key_unresolved';
    private const FULFILL_SKIP_ADDON_AWAITING_CORE = 'addon_awaiting_core_license';

    private static string $fulfillSkipReason = self::FULFILL_SKIP_NONE;

    /**
     * Eventos que pueden volver a procesarse con el mismo Webhook-Id (upsert de add-on/core es idempotente).
     * Excluye order.paid para no duplicar tokens.
     */
    private static function polarEventAllowsDuplicateReplay(string $eventType): bool
    {
        return in_array($eventType, [
            'order.updated',
            'subscription.created',
            'subscription.updated',
            'subscription.active',
            'benefit_grant.created',
            'benefit_grant.updated',
        ], true);
    }

    public static function handle(): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
            Json::respond(405, ['error' => ['message' => 'Method Not Allowed', 'type' => 'method']]);

            return;
        }
        $raw = (string) file_get_contents('php://input');
        $secret = Env::str('POLAR_WEBHOOK_SECRET');
        if ($secret === '' || !StandardWebhooks::verifySymmetricV1($raw, $_SERVER, $secret)) {
            Json::respond(403, ['error' => ['message' => 'Firma webhook inválida', 'type' => 'polar_signature']]);

            return;
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            Json::respond(400, ['error' => ['message' => 'JSON inválido', 'type' => 'parse']]);

            return;
        }
        $type = isset($payload['type']) && is_string($payload['type']) ? trim($payload['type']) : '';
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : [];

        $webhookId = isset($_SERVER['HTTP_WEBHOOK_ID']) && is_string($_SERVER['HTTP_WEBHOOK_ID'])
            ? trim($_SERVER['HTTP_WEBHOOK_ID']) : '';
        if ($webhookId !== '' && LicenseRepository::webhookDeliveryExists($webhookId)) {
            // Re-ejecutar fulfillment idempotente (add-ons / core) tras fixes o replays de Polar.
            // No incluir order.paid: podría duplicar entrega de tokens.
            if (self::polarEventAllowsDuplicateReplay($type)) {
                try {
                    self::fulfill($type, $data);
                } catch (Throwable $e) {
                    if (Env::str('XABIA_DEBUG') !== '') {
                        Json::respond(500, [
                            'error' => [
                                'message' => 'Error al reprocesar webhook duplicado',
                                'type'    => 'polar_fulfillment_duplicate',
                                'detail'  => $e->getMessage(),
                            ],
                        ]);

                        return;
                    }
                    Json::respond(500, ['error' => ['message' => 'Error al reprocesar webhook duplicado', 'type' => 'polar_fulfillment_duplicate']]);

                    return;
                }
            }
            Json::respond(202, ['ok' => true, 'duplicate' => true]);

            return;
        }

        $fulfilled = false;
        $fulfillTypes = [
            'order.paid', 'order.updated',
            'subscription.created', 'subscription.updated', 'subscription.active',
            'benefit_grant.created', 'benefit_grant.updated',
            'subscription.canceled', 'subscription.revoked',
            'benefit_grant.revoked',
        ];
        if (!in_array($type, $fulfillTypes, true)) {
            if ($webhookId !== '') {
                LicenseRepository::recordWebhookDelivery($webhookId);
            }
            Json::respond(202, ['ok' => true, 'ignored' => true, 'type' => $type]);

            return;
        }

        try {
            self::$fulfillSkipReason = self::FULFILL_SKIP_NONE;
            $fulfilled = self::fulfill($type, $data);
            if (!$fulfilled) {
                if (self::$fulfillSkipReason === self::FULFILL_SKIP_ADDON_AWAITING_CORE) {
                    Json::respond(503, [
                        'ok'     => false,
                        'retry'  => true,
                        'reason' => 'addon_awaiting_core_license',
                        'detail' => 'Suscripción de add-on recibida antes de la licencia Core; Polar puede reintentar.',
                    ]);

                    return;
                }
                Json::respond(202, ['ok' => true, 'fulfilled' => false, 'reason' => self::$fulfillSkipReason !== '' ? self::$fulfillSkipReason : 'skipped']);

                return;
            }
            if ($webhookId !== '') {
                LicenseRepository::recordWebhookDelivery($webhookId);
            }
        } catch (Throwable $e) {
            if (Env::str('XABIA_DEBUG') !== '') {
                Json::respond(500, [
                    'error' => [
                        'message' => 'Error al procesar webhook',
                        'type'    => 'polar_fulfillment',
                        'detail'  => $e->getMessage(),
                    ],
                ]);

                return;
            }
            Json::respond(500, ['error' => ['message' => 'Error al procesar webhook', 'type' => 'polar_fulfillment']]);

            return;
        }

        Json::respond(202, ['ok' => true, 'fulfilled' => $fulfilled]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function fulfill(string $eventType, array $data): bool
    {
        $isRevocationEvent = in_array($eventType, ['subscription.canceled', 'subscription.revoked', 'benefit_grant.revoked'], true);
        if ($eventType === 'order.paid' || $eventType === 'order.updated') {
            if (!self::isOrderPaidState($data)) {
                $oid = self::extractOrderIdForLog($data);
                error_log('[xabia-polar] skip: pedido no marcado como pagado (order_id=' . $oid . ', event=' . $eventType . ')');

                return false;
            }
        } elseif ($eventType === 'subscription.created') {
            if (!self::isSubscriptionInitiallyActive($data)) {
                $st = self::extractSubscriptionStatus($data);
                error_log('[xabia-polar] skip: suscripción creada sin estado activo inicial (status=' . $st . ')');

                return false;
            }
        } elseif ($eventType === 'subscription.updated' || $eventType === 'subscription.active') {
            if (!self::isSubscriptionActiveOrTrialing($data)) {
                $st = self::extractSubscriptionStatus($data);
                error_log('[xabia-polar] skip: suscripción no activa (status=' . $st . ', event=' . $eventType . ')');

                return false;
            }
        }

        $email = self::extractCustomerEmail($data);
        if ($email === '') {
            error_log('[xabia-polar] skip: no customer email (' . $eventType . ')');

            return false;
        }
        $meta = self::extractOrderFulfillmentMetadata($data);
        $polarExtracted = self::extractPolarLicenseKeyForWebhook($meta, $data);
        $licenseKey = self::resolveLicenseKey($email, $meta, $data, $polarExtracted);
        if ($licenseKey === '') {
            error_log('[xabia-polar] skip: could not resolve license_key for ' . $email);
            if (self::payloadHasAddonProduct($data) && LicenseRepository::findLicenseKeyByBillingEmail($email) === null) {
                self::$fulfillSkipReason = self::FULFILL_SKIP_ADDON_AWAITING_CORE;
            } else {
                self::$fulfillSkipReason = self::FULFILL_SKIP_LICENSE_UNRESOLVED;
            }

            return false;
        }
        $resolvedDomain = self::resolveClientDomain($meta, $data);
        if ($resolvedDomain !== '' && !LicenseRepository::isPendingAssignmentDomain($resolvedDomain)) {
            LicenseRepository::ensureLicenseDomainRow($licenseKey, $resolvedDomain, $email);
        }
        $productIds = self::extractPolarProductIds($data);
        if ($productIds === []) {
            error_log('[xabia-polar] note: no product ids in payload (' . $eventType . ') — licencia puede haberse creado; entrega de producto omitida.');
        }
        $clientUrl = self::metadataString($meta, ['client_url', 'site_url', 'domain']);
        $polarRef = self::metadataString($meta, ['polar_order_id', 'order_id'])
            ?: (isset($data['id']) && is_string($data['id']) ? $data['id'] : '');

        $tokensDelivered = 0;
        $webhookId = isset($_SERVER['HTTP_WEBHOOK_ID']) && is_string($_SERVER['HTTP_WEBHOOK_ID'])
            ? trim($_SERVER['HTTP_WEBHOOK_ID']) : '';
        foreach ($productIds as $pid) {
            $mapRule = PolarProductMap::resolve($pid);
            $itemMeta = self::mergedMetadataForPolarProduct($data, $pid);
            $metaProductType = self::parseMetaProductType($itemMeta);
            if ($metaProductType !== null) {
                self::fulfillByMetaProductType(
                    $eventType,
                    $data,
                    $licenseKey,
                    $pid,
                    $metaProductType,
                    $mapRule,
                    $itemMeta,
                    $isRevocationEvent,
                    $clientUrl,
                    $webhookId,
                    $tokensDelivered
                );

                continue;
            }

            if ($mapRule === null) {
                if (Env::str('XABIA_DEBUG') !== '') {
                    error_log('[xabia-polar] unmapped product id (PolarProductMap): ' . $pid);
                }

                continue;
            }
            self::fulfillPolarTypedRule(
                $eventType,
                $data,
                $licenseKey,
                $pid,
                $mapRule,
                $isRevocationEvent,
                $clientUrl,
                $webhookId,
                $tokensDelivered,
                $itemMeta
            );
        }
        if ($eventType === 'order.paid' && $tokensDelivered > 0) {
            $orderId = self::extractOrderIdForLog($data);
            error_log(
                '[xabia-polar] Pago confirmado para el pedido [' . $orderId . ']. Entregando ' . (string) $tokensDelivered . ' tokens.'
            );
        }
        if ($polarRef !== '') {
            error_log('[xabia-polar] fulfilled ' . $eventType . ' ref=' . $polarRef . ' license_key=' . $licenseKey);
        }

        return true;
    }

    /**
     * product_type en metadata (initial|renewal|topup): rama DTP; puede combinar meta dinámica + PolarProductMap.
     *
     * @param array<string, mixed>|null $mapRule
     * @param array<string, mixed>      $meta
     */
    private static function fulfillByMetaProductType(
        string $eventType,
        array $data,
        string $licenseKey,
        string $pid,
        string $metaProductType,
        ?array $mapRule,
        array $meta,
        bool $isRevocationEvent,
        string $clientUrl,
        string $webhookId,
        int &$tokensDelivered
    ): void {
        if ($metaProductType === 'topup') {
            if ($eventType !== 'order.paid') {
                return;
            }
            $amount = self::metaPositiveInt($meta, ['tokens', 'token_amount', 'token_pack', 'topup_tokens', 'amount']);
            if ($amount < 1 && $mapRule !== null && (($mapRule['type'] ?? '') === 'tokens')) {
                $amount = (int) ($mapRule['amount'] ?? 0);
            }
            if ($amount < 1) {
                if (Env::str('XABIA_DEBUG') !== '') {
                    error_log('[xabia-polar] product_type=topup sin cantidad válida (tokens/token_amount/…) pid=' . $pid);
                }

                return;
            }
            $businessKey = self::buildTopupBusinessKey($data, $licenseKey, $pid, $amount);
            if (LicenseRepository::webhookBusinessEventExists('polar', $businessKey)) {
                return;
            }
            LicenseRepository::addTokensToWalletForLicenseKey($licenseKey, $amount);
            LicenseRepository::recordWebhookBusinessEvent('polar', $businessKey, $webhookId);
            $tokensDelivered += $amount;

            return;
        }

        $effective = self::resolveEffectiveRuleForInitialRenewal($mapRule, $meta);
        if ($effective === null) {
            if (Env::str('XABIA_DEBUG') !== '') {
                error_log('[xabia-polar] product_type=' . $metaProductType . ' sin regla (map/meta) pid=' . $pid);
            }

            return;
        }

        self::fulfillPolarTypedRule(
            $eventType,
            $data,
            $licenseKey,
            $pid,
            $effective,
            $isRevocationEvent,
            $clientUrl,
            $webhookId,
            $tokensDelivered,
            $meta
        );
    }

    /**
     * Fulfillment según regla type=tokens|addon|core (mapa o sintética desde meta).
     *
     * @param array{type: string, amount?: int, addon_slug?: string, extend_years?: int} $rule
     */
    private static function fulfillPolarTypedRule(
        string $eventType,
        array $data,
        string $licenseKey,
        string $pid,
        array $rule,
        bool $isRevocationEvent,
        string $clientUrl,
        string $webhookId,
        int &$tokensDelivered,
        array $meta = []
    ): void {
        $t = (string) ($rule['type'] ?? '');
        if ($eventType === 'subscription.created' && $t === 'tokens') {
            return;
        }
        if ($t === 'tokens') {
            if ($eventType !== 'order.paid') {
                return;
            }
            $amount = (int) ($rule['amount'] ?? 0);
            if ($amount > 0) {
                $businessKey = self::buildTopupBusinessKey($data, $licenseKey, $pid, $amount);
                if (!LicenseRepository::webhookBusinessEventExists('polar', $businessKey)) {
                    LicenseRepository::addTokensToWalletForLicenseKey($licenseKey, $amount);
                    LicenseRepository::recordWebhookBusinessEvent('polar', $businessKey, $webhookId);
                    $tokensDelivered += $amount;
                }
            }

            return;
        }
        if ($t === 'addon') {
            if ($isRevocationEvent) {
                $slug = trim((string) ($rule['addon_slug'] ?? ''));
                if ($slug !== '') {
                    LicenseRepository::setAddonActivationStatusForLicense($licenseKey, $slug, 'expired');
                }

                return;
            }
            if (self::polarEventFulfillsAddonOrCore($eventType)) {
                $slug = trim((string) ($rule['addon_slug'] ?? ''));
                if ($slug !== '') {
                    $exp = self::polarSubscriptionLikeEvent($eventType)
                        ? self::addonExpiryFromPolarSubscriptionPayload($data)
                        : self::addonExpiryDateOneYear();
                    LicenseRepository::upsertAddonActivation(
                        $licenseKey,
                        $pid,
                        $slug,
                        $exp,
                        $clientUrl,
                        'polar'
                    );
                }
            }

            return;
        }
        if ($t === 'core') {
            if ($isRevocationEvent) {
                LicenseRepository::setLicenseStatusForLicenseKey($licenseKey, 'expired');

                return;
            }
            if (self::polarEventFulfillsAddonOrCore($eventType)) {
                $years = (int) ($rule['extend_years'] ?? 1);
                if ($years < 1) {
                    $years = 1;
                }
                LicenseRepository::extendCoreExpiryForLicenseKey($licenseKey, $years);
                if ($eventType === 'order.paid' && !$isRevocationEvent) {
                    self::grantCoreWelcomeTokens($data, $licenseKey, $pid, $meta, $webhookId, $tokensDelivered);
                }
            }
        }
    }

    /**
     * @param array<string, mixed>|null $mapRule
     * @param array<string, mixed>      $meta
     *
     * @return array{type: string, amount?: int, addon_slug?: string, extend_years?: int}|null
     */
    private static function resolveEffectiveRuleForInitialRenewal(?array $mapRule, array $meta): ?array
    {
        if ($mapRule !== null && isset($mapRule['type']) && is_string($mapRule['type'])) {
            if ($mapRule['type'] === 'tokens') {
                return self::syntheticRuleFromPolarMeta($meta);
            }

            return $mapRule;
        }

        return self::syntheticRuleFromPolarMeta($meta);
    }

    /**
     * Regla inferida solo desde metadata (productos dinámicos en Polar).
     *
     * @param array<string, mixed> $meta
     *
     * @return array{type: string, amount?: int, addon_slug?: string, extend_years?: int}|null
     */
    private static function syntheticRuleFromPolarMeta(array $meta): ?array
    {
        $slug = self::metadataString($meta, ['addon_slug', 'xabia_addon_slug']);
        if ($slug !== '') {
            return ['type' => 'addon', 'addon_slug' => $slug];
        }
        $years = self::metaPositiveInt($meta, ['extend_years', 'core_extend_years', 'extension_years']);
        if ($years > 0) {
            return ['type' => 'core', 'extend_years' => $years];
        }
        $kind = strtolower(self::metadataString($meta, ['product_kind', 'kind', 'license_kind']));
        if ($kind === 'core') {
            $y = self::metaPositiveInt($meta, ['extend_years', 'core_extend_years']);

            return ['type' => 'core', 'extend_years' => $y > 0 ? $y : 1];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return 'initial'|'renewal'|'topup'|null
     */
    private static function parseMetaProductType(array $meta): ?string
    {
        foreach (['product_type', 'productType', 'purchase_type'] as $key) {
            if (!array_key_exists($key, $meta)) {
                continue;
            }
            $v = $meta[$key];
            $raw = strtolower(trim(is_string($v) ? $v : (is_int($v) || is_float($v) ? (string) $v : '')));
            if (in_array($raw, ['initial', 'renewal', 'topup'], true)) {
                return $raw;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $meta
     * @param list<string>         $keys
     */
    private static function metaPositiveInt(array $meta, array $keys): int
    {
        foreach ($keys as $k) {
            if (!array_key_exists($k, $meta)) {
                continue;
            }
            $v = $meta[$k];
            if (is_int($v)) {
                return max(0, $v);
            }
            if (is_float($v)) {
                return max(0, (int) round($v));
            }
            if (is_string($v)) {
                $s = trim($v);
                if ($s !== '' && preg_match('/^-?\d+$/', $s) === 1) {
                    return max(0, (int) $s);
                }
            }
        }

        return 0;
    }

    /**
     * Metadata de checkout fusionada; si hay líneas de pedido con este product_id, sus metadatos tienen prioridad.
     *
     * @param array<string, mixed> $data
     */
    private static function mergedMetadataForPolarProduct(array $data, string $pid): array
    {
        $base = self::extractOrderFulfillmentMetadata($data);
        if (isset($data['product']) && is_array($data['product'])) {
            $prodId = isset($data['product']['id']) ? trim((string) $data['product']['id']) : '';
            if ($prodId !== '' && self::polarProductIdsEqual($prodId, $pid)) {
                $base = array_merge($base, self::extractMetadata($data['product']));
            }
        }
        foreach (['order', 'checkout'] as $wrap) {
            if (!isset($data[$wrap]) || !is_array($data[$wrap])) {
                continue;
            }
            $base = array_merge($base, self::extractMetadata($data[$wrap]));
            foreach (['items', 'order_items', 'lines', 'line_items'] as $ik) {
                if (!isset($data[$wrap][$ik]) || !is_array($data[$wrap][$ik])) {
                    continue;
                }
                foreach ($data[$wrap][$ik] as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    if (!self::lineItemMatchesPolarProduct($item, $pid)) {
                        continue;
                    }
                    $base = array_merge($base, self::extractMetadata($item));
                }
            }
        }
        foreach (['items', 'order_items', 'lines', 'line_items'] as $ik) {
            if (!isset($data[$ik]) || !is_array($data[$ik])) {
                continue;
            }
            foreach ($data[$ik] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if (!self::lineItemMatchesPolarProduct($item, $pid)) {
                    continue;
                }
                $base = array_merge($base, self::extractMetadata($item));
            }
        }

        return $base;
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function lineItemMatchesPolarProduct(array $item, string $pid): bool
    {
        $keys = ['product_id'];
        foreach ($keys as $k) {
            if (isset($item[$k]) && (is_string($item[$k]) || is_int($item[$k]) || is_float($item[$k]))) {
                $raw = trim((string) $item[$k]);
                if ($raw !== '' && self::polarProductIdsEqual($raw, $pid)) {
                    return true;
                }
            }
        }
        if (isset($item['product']) && is_array($item['product']) && isset($item['product']['id'])) {
            $rid = $item['product']['id'];
            if (is_string($rid) || is_int($rid)) {
                $raw = trim((string) $rid);
                if ($raw !== '' && self::polarProductIdsEqual($raw, $pid)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function polarProductIdsEqual(string $a, string $b): bool
    {
        return self::normalizePolarProductUuid($a) === self::normalizePolarProductUuid($b);
    }

    private static function normalizePolarProductUuid(string $id): string
    {
        $id = strtolower(trim($id));
        if ($id === '') {
            return '';
        }
        if (str_starts_with($id, 'prod_')) {
            $id = substr($id, 5);
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function buildTopupBusinessKey(array $data, string $licenseKey, string $productId, int $amount): string
    {
        $orderId = '';
        foreach (['id', 'order_id', 'number'] as $k) {
            if (isset($data[$k]) && (is_string($data[$k]) || is_int($data[$k]))) {
                $v = trim((string) $data[$k]);
                if ($v !== '') {
                    $orderId = strtolower($v);

                    break;
                }
            }
        }
        if ($orderId === '') {
            $orderId = sha1(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
        }

        return 'polar:topup:' . sha1(strtolower($licenseKey) . '|' . strtolower($productId) . '|' . (string) $amount . '|' . $orderId);
    }

    /**
     * Eventos Polar que deben aplicar entrega de addon o extensión Core (alineado con $fulfillTypes).
     */
    private static function polarEventFulfillsAddonOrCore(string $eventType): bool
    {
        return in_array($eventType, [
            'order.paid',
            'order.updated',
            'subscription.created',
            'subscription.updated',
            'subscription.active',
            'benefit_grant.created',
            'benefit_grant.updated',
        ], true);
    }

    /**
     * Usar fecha de periodo de suscripción en payload (si existe) para caducidad del addon.
     */
    private static function polarSubscriptionLikeEvent(string $eventType): bool
    {
        return in_array($eventType, [
            'subscription.created',
            'subscription.updated',
            'subscription.active',
        ], true);
    }

    /**
     * order.paid implica pago; si Polar envía `status`, debe ser un estado pagado.
     *
     * @param array<string, mixed> $data
     */
    private static function isOrderPaidState(array $data): bool
    {
        $status = $data['status'] ?? null;
        if ($status === null || $status === '') {
            return true;
        }
        if (!is_string($status)) {
            return true;
        }
        $s = strtolower(trim($status));

        return in_array($s, ['paid', 'complete', 'completed', 'succeeded', 'success'], true);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractOrderIdForLog(array $data): string
    {
        foreach (['id', 'order_id', 'number'] as $k) {
            if (isset($data[$k]) && (is_string($data[$k]) || is_int($data[$k]))) {
                $v = trim((string) $data[$k]);
                if ($v !== '') {
                    return $v;
                }
            }
        }

        return 'desconocido';
    }

    /**
     * Suscripción recién creada: solo cumplir Core/Addon si el estado es activo (o equivalente).
     *
     * @param array<string, mixed> $data
     */
    private static function isSubscriptionInitiallyActive(array $data): bool
    {
        return self::isSubscriptionActiveOrTrialing($data);
    }

    /**
     * Estado actual de suscripción (created / updated).
     *
     * @param array<string, mixed> $data
     */
    private static function isSubscriptionActiveOrTrialing(array $data): bool
    {
        $sub = isset($data['subscription']) && is_array($data['subscription']) ? $data['subscription'] : $data;
        $status = $sub['status'] ?? $data['status'] ?? null;
        if ($status === null || $status === '') {
            return true;
        }
        if (!is_string($status)) {
            return true;
        }
        $s = strtolower(trim($status));

        return in_array($s, ['active', 'trialing'], true);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractSubscriptionStatus(array $data): string
    {
        $sub = isset($data['subscription']) && is_array($data['subscription']) ? $data['subscription'] : $data;
        $status = $sub['status'] ?? $data['status'] ?? null;

        return is_string($status) ? $status : '';
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $data
     */
    private static function resolveLicenseKey(string $email, array $meta, array $data, string $polarFromPayload): string
    {
        $fromMeta = self::metadataString($meta, ['license_key', 'xabia_license_key', 'digixop_license_key']);
        if ($fromMeta !== '' && LicenseRepository::findAllByLicenseKey($fromMeta) !== []) {
            LicenseRepository::touchBillingEmailForLicenseKey($fromMeta, $email);

            return PolarLicenseKey::normalize($fromMeta);
        }

        $polar = $polarFromPayload !== '' ? PolarLicenseKey::normalize($polarFromPayload) : '';
        if ($polar === '' && $fromMeta !== '' && PolarLicenseKey::isValidFormat($fromMeta)) {
            $polar = PolarLicenseKey::normalize($fromMeta);
        }
        if ($polar === '' && self::shouldSynthesizeInitialLicenseKey($meta, $data)) {
            $polar = self::synthesizeLicenseKeyFromOrder($data);
            if ($polar !== '') {
                error_log('[xabia-polar] synthesized_initial_license_key order_id=' . self::extractOrderIdForLog($data) . ' key=' . $polar);
            }
        }
        if ($polar !== '') {
            if (LicenseRepository::findAllByLicenseKey($polar) !== []) {
                LicenseRepository::touchBillingEmailForLicenseKey($polar, $email);

                return $polar;
            }
            $domain = self::resolveClientDomain($meta, $data);
            if (LicenseRepository::isPendingAssignmentDomain($domain)) {
                error_log('[xabia-polar] note: checkout sin dominio; licencia provisional ' . LicenseRepository::PENDING_ASSIGNMENT_DOMAIN . ' (email=' . $email . ')');
                $domain = LicenseRepository::PENDING_ASSIGNMENT_DOMAIN;
            }
            $id = LicenseRepository::createLicenseWithWalletFromPolar($email, $domain, $polar);
            if ($id < 1) {
                return '';
            }
            error_log('[xabia-polar] auto_created_license email=' . $email . ' domain=' . $domain . ' key=' . $polar);

            return $polar;
        }

        $domain = self::resolveClientDomain($meta, $data);
        if (!LicenseRepository::isPendingAssignmentDomain($domain)) {
            $byDomain = LicenseRepository::findLicenseKeyByClientDomain($domain);
            if ($byDomain !== null && $byDomain !== '') {
                LicenseRepository::touchBillingEmailForLicenseKey($byDomain, $email);
                error_log('[xabia-polar] resolved license by domain=' . $domain . ' key=' . $byDomain);

                return $byDomain;
            }
        }

        $emailKeys = LicenseRepository::findDistinctLicenseKeysByBillingEmail($email);
        if (count($emailKeys) === 1) {
            LicenseRepository::touchBillingEmailForLicenseKey($emailKeys[0], $email);

            return $emailKeys[0];
        }
        if (count($emailKeys) > 1) {
            error_log('[xabia-polar] skip: email ' . $email . ' has ' . count($emailKeys) . ' licenses; require polar license key or client_url in checkout');
        }

        return '';
    }

    private static function isPolarLicenseKeyString(string $s): bool
    {
        return PolarLicenseKey::isValidFormat($s);
    }

    /**
     * Clave Polar XABIA--… en payload (license_id/benefit_id, benefits[], metadata o recorrido profundo).
     *
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $data
     */
    private static function extractPolarLicenseKeyForWebhook(array $meta, array $data): string
    {
        foreach (['license_id', 'benefit_id'] as $k) {
            if (isset($data[$k]) && is_string($data[$k]) && self::isPolarLicenseKeyString($data[$k])) {
                return trim($data[$k]);
            }
        }
        foreach (['benefits', 'benefit_grants', 'license_keys'] as $block) {
            if (!isset($data[$block]) || !is_array($data[$block])) {
                continue;
            }
            foreach ($data[$block] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                foreach (['license_id', 'benefit_id', 'id', 'key', 'license_key'] as $ik) {
                    if (isset($item[$ik]) && is_string($item[$ik]) && self::isPolarLicenseKeyString($item[$ik])) {
                        return trim($item[$ik]);
                    }
                }
            }
        }
        foreach (['license_key', 'xabia_license_key', 'digixop_license_key'] as $mk) {
            if (isset($meta[$mk]) && is_string($meta[$mk]) && self::isPolarLicenseKeyString($meta[$mk])) {
                return trim($meta[$mk]);
            }
        }
        $found = self::scanValueForPolarLicenseKey($meta, 0);
        if ($found !== '') {
            return $found;
        }

        return self::scanValueForPolarLicenseKey($data, 0);
    }

    /**
     * @param mixed $value
     */
    private static function scanValueForPolarLicenseKey($value, int $depth): string
    {
        if ($depth > 8) {
            return '';
        }
        if (is_string($value)) {
            $t = trim($value);
            if (self::isPolarLicenseKeyString($t)) {
                return $t;
            }
            $extracted = PolarLicenseKey::extractFromText($t);

            return $extracted !== '' ? $extracted : '';
        }
        if (!is_array($value)) {
            return '';
        }
        foreach ($value as $v) {
            $f = self::scanValueForPolarLicenseKey($v, $depth + 1);
            if ($f !== '') {
                return $f;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $data
     */
    private static function resolveClientDomain(array $meta, array $data): string
    {
        $raw = self::metadataString($meta, ['client_domain', 'client_url', 'site_url', 'domain', 'site', 'wordpress_url']);
        $host = Domain::normalize($raw);
        if ($host !== '') {
            return $host;
        }

        return LicenseRepository::PENDING_ASSIGNMENT_DOMAIN;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractCustomerEmail(array $data): string
    {
        foreach (['customer_email', 'email'] as $k) {
            if (isset($data[$k]) && is_string($data[$k])) {
                $e = strtolower(trim($data[$k]));
                if ($e !== '') {
                    return $e;
                }
            }
        }
        if (isset($data['customer']) && is_array($data['customer'])) {
            $c = $data['customer'];
            if (isset($c['email']) && is_string($c['email'])) {
                $e = strtolower(trim($c['email']));
                if ($e !== '') {
                    return $e;
                }
            }
        }
        foreach (['user', 'grantee'] as $gk) {
            if (isset($data[$gk]) && is_array($data[$gk]) && isset($data[$gk]['email']) && is_string($data[$gk]['email'])) {
                $e = strtolower(trim($data[$gk]['email']));
                if ($e !== '') {
                    return $e;
                }
            }
        }
        if (isset($data['order']) && is_array($data['order'])) {
            $o = $data['order'];
            if (isset($o['customer_email']) && is_string($o['customer_email'])) {
                $e = strtolower(trim($o['customer_email']));
                if ($e !== '') {
                    return $e;
                }
            }
            if (isset($o['customer']) && is_array($o['customer']) && isset($o['customer']['email']) && is_string($o['customer']['email'])) {
                $e = strtolower(trim($o['customer']['email']));
                if ($e !== '') {
                    return $e;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function extractMetadata(array $data): array
    {
        $out = [];
        foreach (['metadata', 'custom_data', 'customer_metadata', 'meta', 'custom_field_data'] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) {
                $out = array_merge($out, $data[$k]);
            }
        }

        return $out;
    }

    /**
     * Metadata fusionada del pedido/suscripción (order + customer + product embebido).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function extractOrderFulfillmentMetadata(array $data): array
    {
        $out = self::extractMetadata($data);
        foreach (['customer', 'product', 'checkout'] as $nested) {
            if (isset($data[$nested]) && is_array($data[$nested])) {
                $out = array_merge($out, self::extractMetadata($data[$nested]));
            }
        }

        return $out;
    }

    /**
     * Compra Core inicial (web_oficial / Polar) sin clave Polar en payload: derivar XABIA--{ORDER_UUID}.
     *
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $data
     */
    private static function shouldSynthesizeInitialLicenseKey(array $meta, array $data): bool
    {
        if (self::parseMetaProductType($meta) !== 'initial') {
            return false;
        }
        $pid = '';
        if (isset($data['product_id']) && (is_string($data['product_id']) || is_int($data['product_id']))) {
            $pid = trim((string) $data['product_id']);
        } elseif (isset($data['product']['id']) && (is_string($data['product']['id']) || is_int($data['product']['id']))) {
            $pid = trim((string) $data['product']['id']);
        }
        if ($pid === '') {
            return false;
        }
        $rule = PolarProductMap::resolve($pid);

        return $rule !== null && (($rule['type'] ?? '') === 'core');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function synthesizeLicenseKeyFromOrder(array $data): string
    {
        $orderId = isset($data['id']) && is_string($data['id']) ? trim($data['id']) : '';
        if ($orderId === '' || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $orderId) !== 1) {
            return '';
        }

        return PolarLicenseKey::normalize('XABIA--' . strtoupper($orderId));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function payloadHasAddonProduct(array $data): bool
    {
        foreach (self::extractPolarProductIds($data) as $pid) {
            $mapRule = PolarProductMap::resolve($pid);
            if ($mapRule !== null && (($mapRule['type'] ?? '') === 'addon')) {
                return true;
            }
            $itemMeta = self::mergedMetadataForPolarProduct($data, $pid);
            $effective = self::resolveEffectiveRuleForInitialRenewal($mapRule, $itemMeta);
            if ($effective !== null && (($effective['type'] ?? '') === 'addon')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    private static function grantCoreWelcomeTokens(
        array $data,
        string $licenseKey,
        string $pid,
        array $meta,
        string $webhookId,
        int &$tokensDelivered
    ): void {
        $amount = self::metaPositiveInt($meta, ['welcome_tokens', 'tokens', 'token_amount', 'token_pack', 'initial_tokens', 'renewal_tokens']);
        if ($amount < 1) {
            $amount = self::CORE_WELCOME_TOKENS;
        }
        $businessKey = 'polar:core_welcome:' . sha1(strtolower($licenseKey) . '|' . strtolower($pid) . '|' . self::extractOrderIdForLog($data));
        if (LicenseRepository::webhookBusinessEventExists('polar', $businessKey)) {
            return;
        }
        LicenseRepository::addTokensToWalletForLicenseKey($licenseKey, $amount);
        LicenseRepository::recordWebhookBusinessEvent('polar', $businessKey, $webhookId);
        $tokensDelivered += $amount;
    }

    /**
     * @param array<string, mixed>    $meta
     * @param list<string> $keys
     */
    private static function metadataString(array $meta, array $keys): string
    {
        foreach ($keys as $k) {
            if (isset($meta[$k]) && is_string($meta[$k])) {
                $s = trim($meta[$k]);
                if ($s !== '') {
                    return $s;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private static function extractPolarProductIds(array $data): array
    {
        $out = [];
        $add = static function (mixed $raw) use (&$out): void {
            if ($raw === null || (!is_string($raw) && !is_int($raw) && !is_float($raw))) {
                return;
            }
            $id = trim((string) $raw);
            if ($id === '') {
                return;
            }
            if (str_starts_with($id, 'prod_')) {
                $out[] = strtolower($id);

                return;
            }
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1) {
                $out[] = 'prod_' . strtolower($id);
            }
        };

        /**
         * Recorrido acotado: Polar anida product_id / product.id (p. ej. bajo price, checkout, customer).
         *
         * @param array<string, mixed> $node
         */
        $walk = static function (array $node, int $depth) use (&$walk, $add): void {
            if ($depth > 16) {
                return;
            }
            foreach ($node as $k => $v) {
                if ($k === 'product_id') {
                    $add($v);
                }
                if ($k === 'product' && is_array($v) && array_key_exists('id', $v)) {
                    $add($v['id']);
                }
                if (is_array($v)) {
                    $walk($v, $depth + 1);
                }
            }
        };

        $walk($data, 0);

        return array_values(array_unique($out));
    }

    private static function addonExpiryDateOneYear(): string
    {
        $utc = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $utc->add(new DateInterval('P1Y'))->format('Y-m-d');
    }

    /**
     * Fecha de fin de periodo de la suscripción Polar (si viene en el payload); si no, +1 año.
     *
     * @param array<string, mixed> $data
     */
    private static function addonExpiryFromPolarSubscriptionPayload(array $data): string
    {
        $sub = isset($data['subscription']) && is_array($data['subscription']) ? $data['subscription'] : $data;
        foreach (['current_period_end', 'current_period_end_at', 'ends_at', 'end_at'] as $k) {
            if (!isset($sub[$k])) {
                continue;
            }
            $v = $sub[$k];
            if (is_int($v)) {
                if ($v > 0) {
                    return gmdate('Y-m-d', $v);
                }

                continue;
            }
            if (is_string($v) && trim($v) !== '') {
                $ts = strtotime($v);
                if ($ts !== false) {
                    return gmdate('Y-m-d', $ts);
                }
            }
        }

        return self::addonExpiryDateOneYear();
    }
}
