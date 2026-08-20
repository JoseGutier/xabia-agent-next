<?php
/**
 * Límites de entrada del usuario en el chat (anti-abuso / tokens).
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Xabia_Chat_Input')) :

final class Xabia_Chat_Input {
    public const DEFAULT_MAX_LINES = 8;
    public const DEFAULT_MAX_CHARS = 800;

    public static function max_lines(): int {
        $n = (int) apply_filters('xabia_user_message_max_lines', self::DEFAULT_MAX_LINES);

        return max(1, min(20, $n));
    }

    public static function max_chars(): int {
        $n = (int) apply_filters('xabia_user_message_max_chars', self::DEFAULT_MAX_CHARS);

        return max(80, min(4000, $n));
    }

    public static function clamp(string $message): string {
        $message = trim(sanitize_textarea_field($message));
        if ($message === '') {
            return '';
        }

        $max_chars = self::max_chars();
        if (function_exists('mb_strlen') && mb_strlen($message) > $max_chars) {
            $message = (string) mb_substr($message, 0, $max_chars);
        } elseif (strlen($message) > $max_chars) {
            $message = substr($message, 0, $max_chars);
        }

        $lines = preg_split('/\R/u', $message);
        if (!is_array($lines)) {
            $lines = [$message];
        }
        $max_lines = self::max_lines();
        if (count($lines) > $max_lines) {
            $lines = array_slice($lines, 0, $max_lines);
            $message = implode("\n", $lines);
        }

        return trim($message);
    }
}

endif;
