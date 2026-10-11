<?php
/**
 * Opening hours as a merchant types them, read into the days and times the Parla server expects.
 *
 * No WordPress here beyond remove_accents when it exists, so wordpress-plugin/tests can load this
 * file on its own.
 */

if (!defined('ABSPATH') && !defined('PARLA_TESTING')) {
    exit;
}

/**
 * "Mon-Fri 09:00-18:00, Sat 10:00-14:00" -> { "1": {from,to}, ... } with 0 = Sunday.
 * Anything it cannot read is ignored rather than guessed.
 */
function parla_parse_hours(string $text): array
{
    $days = [];
    foreach (preg_split('/[,;\n]+/u', $text) as $chunk) {
        $chunk = trim(parla_fold($chunk));
        // A day or a span of days ("mon-fri", "lun - ven", "lundi au vendredi"), then two times.
        $time = '(\d{1,2})(?:[:h.](\d{2}))?\s*h?';
        $pattern = '/^([a-z]+)\.?(?:\s*(?:-|–|to|au|a|bis|al|tot)\s*([a-z]+)\.?)?\s*:?\s*' . $time . '\s*(?:-|–|to|a|au|bis|al|tot)\s*' . $time . '/u';
        if (!preg_match($pattern, $chunk, $m)) {
            continue;
        }
        $start = parla_day_index($m[1]);
        $end = ($m[2] ?? '') !== '' ? parla_day_index($m[2]) : $start;
        $from = parla_clock($m[3], $m[4] ?? '');
        $to = parla_clock($m[5], $m[6] ?? '');
        if ($start === null || $end === null || $from === null || $to === null) {
            continue;
        }
        for ($d = $start; ; $d = ($d + 1) % 7) {
            $days[(string) $d] = ['from' => $from, 'to' => $to];
            if ($d === $end) {
                break;
            }
        }
    }
    return $days;
}

/** Lower case without accents, so "Mié", "mie" and "MIE" are one word. */
function parla_fold(string $text): string
{
    $text = function_exists('remove_accents')
        ? remove_accents($text)
        : strtr($text, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'á' => 'a', 'ã' => 'a', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n', 'É' => 'e', 'À' => 'a', 'Á' => 'a']);
    return function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
}

/**
 * A day name, short or long, in any language the chat speaks: Mon, lundi, lun., Montag, Mo,
 * maandag, lunes, lunedì, segunda... The longest known beginning wins, so "mar" is Tuesday and
 * "ma" (Dutch) is Monday, "dom" is Sunday and "do" (German, Dutch) is Thursday.
 */
function parla_day_index(string $word): ?int
{
    static $starts = [
        'sun' => 0, 'dim' => 0, 'dom' => 0, 'so' => 0, 'zo' => 0,
        'mon' => 1, 'lun' => 1, 'mo' => 1, 'ma' => 1, 'seg' => 1,
        'tue' => 2, 'mar' => 2, 'di' => 2, 'ter' => 2,
        'wed' => 3, 'mer' => 3, 'mie' => 3, 'mi' => 3, 'wo' => 3, 'qua' => 3,
        'thu' => 4, 'jeu' => 4, 'jue' => 4, 'gio' => 4, 'do' => 4, 'qui' => 4,
        'fri' => 5, 'ven' => 5, 'vie' => 5, 'fr' => 5, 'vr' => 5, 'sex' => 5,
        'sat' => 6, 'sam' => 6, 'sab' => 6, 'sa' => 6, 'za' => 6,
    ];
    $best = null;
    $bestLength = 0;
    foreach ($starts as $start => $day) {
        if (strlen($start) > $bestLength && strncmp($word, $start, strlen($start)) === 0) {
            $best = $day;
            $bestLength = strlen($start);
        }
    }
    return $best;
}

/** "9", "9h", "9h30", "09:30" or "9.30" as "09:30"; null for an hour that is not one. */
function parla_clock(string $hours, string $minutes): ?string
{
    $h = (int) $hours;
    $m = $minutes === '' ? 0 : (int) $minutes;
    if ($h > 24 || $m > 59) {
        return null;
    }
    return sprintf('%02d:%02d', $h, $m);
}

