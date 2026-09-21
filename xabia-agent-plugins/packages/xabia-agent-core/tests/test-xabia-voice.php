<?php
/**
 * Tests for Xabia_Voice locale and spoken-format helpers.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/class-xabia-voice.php';

function xabia_voice_assert(bool $cond, string $msg): void {
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
}

xabia_voice_assert(Xabia_Voice::lang_code_to_locale('eu') === 'eu-ES', 'eu → eu-ES');
xabia_voice_assert(Xabia_Voice::lang_code_to_locale('fr') === 'fr-FR', 'fr → fr-FR');
xabia_voice_assert(Xabia_Voice::lang_code_to_locale('eu-ES') === 'eu-ES', 'eu-ES passthrough');
xabia_voice_assert(Xabia_Voice::normalize_lang_code('fr-FR') === 'fr', 'fr-FR → fr');
xabia_voice_assert(Xabia_Voice::normalize_lang_code('eu_ES') === 'eu', 'eu_ES → eu');

$directive = Xabia_Voice::spoken_format_directive();
xabia_voice_assert(strpos($directive, 'FORMATO HABLADO') !== false, 'spoken directive present');

xabia_voice_assert(Xabia_Voice::spell_number_eu(15) === 'hamabost', 'eu 15');
xabia_voice_assert(strpos(Xabia_Voice::localize_text_for_tts('a las 19:30 cuesta 15 euros.', 'eu'), 'diecinueve') === false, 'no spanish 19');
xabia_voice_assert(strpos(Xabia_Voice::localize_text_for_tts('a las 19:30 cuesta 15 euros.', 'eu'), '19:30ean') !== false, 'eu time suffix');

$eventLine = 'Data: 2026-10-17 Lekua: La Encartada Deskribapena: bisita gidatua.';
$broken = Xabia_Voice::insert_structural_tts_breaks($eventLine);
xabia_voice_assert(strpos($broken, "\n") !== false, 'structural breaks insert newlines');
xabia_voice_assert(strpos($broken, 'Lekua:') !== false && strpos($broken, 'Deskribapena:') !== false, 'labels preserved');

$euFields = "Herritarren engranajea: industria-ondarearen etorkizunaren proiekzioa\nNoiz: 2026-10-17\nNon: La Encartada";
$euBroken = Xabia_Voice::insert_structural_tts_breaks(str_replace("\n", ' ', $euFields));
xabia_voice_assert(strpos($euBroken, 'Noiz:') !== false && strpos($euBroken, "\n") !== false, 'Noiz/Non structural breaks');

$paused = Xabia_Voice::inject_speech_pauses($broken, 'plain');
xabia_voice_assert(strpos($paused, ' … ') !== false, 'pauses between fields');

echo "OK xabia-voice\n";
