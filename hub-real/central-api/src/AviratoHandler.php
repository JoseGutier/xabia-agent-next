<?php

declare(strict_types=1);

namespace XabiaCentral;

final class AviratoHandler
{
    private const BOOKING_BASE_URL = 'https://booking.avirato.com/';

    /** @var list<string> */
    private const ALWAYS_EXCLUDED_TERMS = ['SALA PARA EVENTOS', 'BONO', 'SPA', 'MASSAGE'];

    /**
     * @return array<string, mixed>
     */
    public static function getAvailability(string $establishmentId, string $checkin, string $checkout, int $adults = 2, int $children = 0, string $filterKeyword = ''): array
    {
        $establishmentId = trim($establishmentId);
        if ($establishmentId === '') {
            return self::missingConfig($checkin, $checkout, $adults, $children, $filterKeyword);
        }

        $query = [
            'code'      => $establishmentId,
            'startDate' => self::toBookingDate($checkin),
            'endDate'   => self::toBookingDate($checkout),
            'lang'      => 'es',
        ];
        $url = self::BOOKING_BASE_URL . '?' . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: es-ES,es;q=0.9',
                'Cache-Control: no-cache',
                'Pragma: no-cache',
            ],
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return self::fallbackOrError($checkin, $checkout, $adults, $children, $filterKeyword, $query, 'curl failure llamando al motor publico de Avirato.');
        }
        if ($code < 200 || $code >= 300) {
            return self::fallbackOrError(
                $checkin,
                $checkout,
                $adults,
                $children,
                $filterKeyword,
                $query,
                'HTTP no exitoso en scraping de Avirato.',
                $code
            );
        }

        $rooms = self::extractRoomsFromHtml($raw);
        if ($rooms === []) {
            return self::fallbackOrError(
                $checkin,
                $checkout,
                $adults,
                $children,
                $filterKeyword,
                $query,
                'No se pudo extraer rooms_from_server del HTML.',
                $code
            );
        }

        $filteredRooms = self::filterRooms($rooms, $filterKeyword);
        if ($filteredRooms === []) {
            return self::fallbackOrError(
                $checkin,
                $checkout,
                $adults,
                $children,
                $filterKeyword,
                $query,
                'No hay habitaciones tras aplicar el filtro modular.',
                $code
            );
        }

        return [
            'ok'      => true,
            'status'  => 'success',
            'source'  => 'avirato_public_scraping',
            'code'    => $code,
            'input'   => [
                'checkin'        => $checkin,
                'checkout'       => $checkout,
                'adults'         => max(1, $adults),
                'children'       => max(0, $children),
                'filter_keyword' => $filterKeyword,
            ],
            'query'   => $query,
            'rooms'   => array_values($filteredRooms),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function missingConfig(string $checkin = '', string $checkout = '', int $adults = 2, int $children = 0, string $filterKeyword = ''): array
    {
        return [
            'ok'      => false,
            'status'  => 'error',
            'source'  => 'avirato_missing_config',
            'message' => 'Falta la configuración de Avirato: el cliente debe enviar avirato.establishment_id.',
            'code'    => 400,
            'input'   => [
                'checkin'        => $checkin,
                'checkout'       => $checkout,
                'adults'         => max(1, $adults),
                'children'       => max(0, $children),
                'filter_keyword' => $filterKeyword,
            ],
        ];
    }

    public static function canAnswerAvailability(array $availability): bool
    {
        $rooms = $availability['rooms'] ?? null;

        return (($availability['ok'] ?? false) === true || ($availability['status'] ?? '') === 'success')
            && is_array($rooms)
            && $rooms !== [];
    }

    public static function formatAvailabilityAnswer(array $availability): string
    {
        $input = is_array($availability['input'] ?? null) ? $availability['input'] : [];
        $checkin = (string) ($input['checkin'] ?? '');
        $checkout = (string) ($input['checkout'] ?? '');
        $rooms = is_array($availability['rooms'] ?? null) ? $availability['rooms'] : [];
        $lines = [];
        $title = 'Sí, he encontrado disponibilidad';
        if ($checkin !== '' && $checkout !== '') {
            $title .= ' del ' . $checkin . ' al ' . $checkout;
        }
        $lines[] = $title . '.';
        $lines[] = '';
        $shown = 0;
        foreach ($rooms as $room) {
            if (!is_array($room)) {
                continue;
            }
            $name = (string) ($room['nombreEspacio'] ?? ($room['name'] ?? ($room['nombre'] ?? 'Habitación disponible')));
            $type = (string) ($room['subtipoEspacio'] ?? '');
            $price = $room['precio'] ?? ($room['price'] ?? ($room['importe'] ?? null));
            $currency = (string) ($room['currency'] ?? ($room['moneda'] ?? 'EUR'));
            $label = $type !== '' && stripos($name, $type) === false ? $type . ' - ' . $name : $name;
            $line = '- ' . $label;
            if (is_numeric($price)) {
                $line .= ': ' . number_format((float) $price, 2, ',', '.') . ' ' . $currency;
            }
            $lines[] = $line;
            $shown++;
            if ($shown >= 6) {
                break;
            }
        }
        if (count($rooms) > $shown) {
            $lines[] = '- Hay más opciones disponibles.';
        }
        $lines[] = '';
        $lines[] = 'Los precios y disponibilidad proceden del motor de reservas de Avirato y pueden cambiar hasta completar la reserva.';

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private static function mockAvailability(string $checkin, string $checkout, int $adults, int $children, string $filterKeyword, string $reason): array
    {
        return [
            'status' => 'success',
            'source' => 'MOCK_DATA_DEMO',
            'input'  => [
                'checkin'     => $checkin,
                'checkout'    => $checkout,
                'adults'      => $adults,
                'children'    => $children,
                'filter'      => $filterKeyword,
                'reason'      => $reason,
                'description' => 'Habitacion Doble Estandar, Disponible, 120EUR',
            ],
            'rooms' => [
                [
                    'name'           => 'Habitacion Doble Estandar',
                    'subtipoEspacio' => 'Habitacion Doble',
                    'available'      => true,
                    'price'          => 120,
                    'currency'       => 'EUR',
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function extractRoomsFromHtml(string $html): array
    {
        $candidates = [];
        if (preg_match('/:rooms_from_server\s*=\s*(["\'])(.*?)\1/s', $html, $m) === 1) {
            $candidates[] = (string) $m[2];
        }
        if (preg_match('/rooms_from_server\s*=\s*(["\'])(.*?)\1/s', $html, $m2) === 1) {
            $candidates[] = (string) $m2[2];
        }
        if (preg_match('/:rooms_from_server\s*=\s*&quot;(.*?)&quot;/s', $html, $m3) === 1) {
            $candidates[] = (string) $m3[1];
        }
        $decodedHtml = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($decodedHtml !== $html && preg_match('/:rooms_from_server\s*=\s*(["\'])(.*?)\1/s', $decodedHtml, $m4) === 1) {
            $candidates[] = (string) $m4[2];
        }

        foreach (array_values(array_unique($candidates)) as $candidate) {
            $attr = html_entity_decode((string) $candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $decoded = json_decode($attr, true);
            if (is_array($decoded)) {
                return array_values(array_filter($decoded, static fn ($room) => is_array($room)));
            }
        }

        return [];
    }

    /**
     * @param list<array<string, mixed>> $rooms
     * @return list<array<string, mixed>>
     */
    private static function filterRooms(array $rooms, string $filterKeyword): array
    {
        $normalizedFilter = self::normalizeText($filterKeyword);

        $filtered = [];
        foreach ($rooms as $room) {
            $subtipo = (string) ($room['subtipoEspacio'] ?? '');
            $name = (string) ($room['nombreEspacio'] ?? ($room['name'] ?? ($room['nombre'] ?? '')));
            $haystack = self::normalizeText($subtipo . ' ' . $name);
            if ($haystack === '') {
                continue;
            }
            if (self::containsExcludedTerm($haystack)) {
                continue;
            }
            if ($normalizedFilter !== '' && !str_contains(self::normalizeText($subtipo), $normalizedFilter)) {
                continue;
            }
            $filtered[] = $room;
        }

        return $filtered;
    }

    private static function containsExcludedTerm(string $haystack): bool
    {
        foreach (self::ALWAYS_EXCLUDED_TERMS as $term) {
            if (str_contains($haystack, self::normalizeText($term))) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }

    private static function toBookingDate(string $date): string
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if ($dt === false) {
            return $date;
        }

        return $dt->format('d-m-Y');
    }

    /**
     * @return array<string, mixed>
     */
    private static function fallbackOrError(
        string $checkin,
        string $checkout,
        int $adults,
        int $children,
        string $filterKeyword,
        array $query,
        string $reason,
        int $code = 0
    ): array {
        if (self::shouldUseMockFallback()) {
            return self::mockAvailability($checkin, $checkout, $adults, $children, $filterKeyword, $reason);
        }

        return [
            'ok'      => false,
            'status'  => 'error',
            'source'  => 'avirato_scraping_error',
            'message' => $reason,
            'code'    => $code,
            'query'   => $query,
        ];
    }

    private static function shouldUseMockFallback(): bool
    {
        $env = self::normalizeText(Env::str('APP_ENV', Env::str('XABIA_ENV', Env::str('ENVIRONMENT', ''))));

        return in_array($env, ['dev', 'development', 'local'], true);
    }
}

