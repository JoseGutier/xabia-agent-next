<?php
/**
 * Contexto LITE: inyección CSV plana (clave → valor). Sin lógica PRO ni plantillas de prompts avanzadas.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Xabia_Lite_Context {

    /**
     * Convierte filas CSV en texto neutro para el system prompt LITE.
     *
     * @return string Bloque plano; vacío si no hay CSV legible.
     */
    public static function build_catalog_block(): string {
        $path = Xabia_Mode::lite_csv_path();
        if ($path === '' || !is_readable($path)) {
            return '';
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        $header = fgetcsv($handle);
        if (!is_array($header) || $header === []) {
            fclose($handle);
            return '';
        }

        $columns = array_map(static function ($col) {
            return sanitize_text_field((string) $col);
        }, $header);

        $lines = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (!is_array($row) || $row === []) {
                continue;
            }
            $pairs = [];
            foreach ($columns as $i => $label) {
                if ($label === '') {
                    continue;
                }
                $value = isset($row[$i]) ? sanitize_text_field((string) $row[$i]) : '';
                if ($value === '') {
                    continue;
                }
                $pairs[] = $label . ': ' . $value;
            }
            if ($pairs !== []) {
                $lines[] = implode(' | ', $pairs);
            }
        }

        fclose($handle);

        if ($lines === []) {
            return '';
        }

        return implode("\n", $lines);
    }

    /**
     * System prompt LITE mínimo: instrucciones del usuario + catálogo plano.
     */
    public static function build_system_prompt(string $user_instructions): string {
        $parts = [];
        $user_instructions = trim(sanitize_textarea_field($user_instructions));
        if ($user_instructions !== '') {
            $parts[] = $user_instructions;
        }

        $catalog = self::build_catalog_block();
        if ($catalog !== '') {
            $parts[] = "Catalog:\n" . $catalog;
        }

        return implode("\n\n", $parts);
    }
}
