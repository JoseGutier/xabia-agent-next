<?php

declare(strict_types=1);

namespace XabiaCentral;

final class Json
{
    /**
     * @return array<string, mixed>|null
     */
    public static function readBody(): ?array
    {
        $raw = (string) file_get_contents('php://input');
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed>|list<mixed> $data
     */
    public static function respond(int $code, array $data): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
