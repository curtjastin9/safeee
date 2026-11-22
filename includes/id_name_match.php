<?php
declare(strict_types=1);

/**
 * ---------------------------------------------------------------------
 * Name comparison for ID screening (Phase 2). Pure functions, no I/O.
 *
 * A name mismatch NEVER auto-rejects: names are OCR'd / typed by people and are
 * full of legitimate variation (middle names, "DE LA CRUZ" vs "DELA CRUZ",
 * ñ, suffixes, surname-first order). The result only feeds the admin queue.
 * ---------------------------------------------------------------------
 */

if (!defined('ID_NAME_PASS_MIN'))  define('ID_NAME_PASS_MIN', 0.92);   // >= this: names agree
if (!defined('ID_NAME_ADMIN_MIN')) define('ID_NAME_ADMIN_MIN', 0.60);  // between: "could be a typo" -> admin (medium); below -> admin (high)

/** Upper-case ASCII, accents/ñ folded, punctuation -> space, Filipino particles joined, suffixes dropped. */
function id_name_normalize(string $name): string
{
    $name = trim($name);
    if (class_exists('Normalizer')) {
        $d = Normalizer::normalize($name, Normalizer::FORM_D);
        if (is_string($d)) $name = preg_replace('/\p{Mn}+/u', '', $d) ?? $name;
    }
    $name = strtr($name, [
        'Ñ' => 'N', 'ñ' => 'n', 'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'ú' => 'u', 'ù' => 'u',
        'û' => 'u', 'ü' => 'u', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
    ]);
    $name = strtoupper($name);
    $name = preg_replace('/[^A-Z\s]/', ' ', $name) ?? '';
    $name = ' ' . preg_replace('/\s+/', ' ', $name) . ' ';
    // "DE LA CRUZ" / "DE LOS SANTOS" / "DEL" -> single token, so they match "DELA CRUZ" / "DELOS SANTOS".
    $name = preg_replace('/ DE (LA|LAS|LOS) /', ' DE$1 ', $name) ?? $name;
    // generational suffixes carry no identity signal here
    $name = preg_replace('/ (JR|SR|II|III|IV)(?= )/', '', $name) ?? $name;
    return trim(preg_replace('/\s+/', ' ', $name) ?? '');
}

/** Surname-first IDs ("DELA CRUZ, JUAN M.") -> "JUAN M DELA CRUZ". Only when there is a comma. */
function id_name_reorder_commas(string $name): string
{
    if (substr_count($name, ',') === 1) {
        [$last, $rest] = array_map('trim', explode(',', $name, 2));
        return $rest . ' ' . $last;
    }
    return $name;
}

/** @return string[] */
function id_name_tokens(string $name): array
{
    $n = id_name_normalize(id_name_reorder_commas($name));
    return $n === '' ? [] : explode(' ', $n);
}

/** 0..1 similarity of two single tokens (Levenshtein ratio; an initial matches a full name). */
function id_token_similarity(string $a, string $b): float
{
    if ($a === $b) return 1.0;
    if ($a === '' || $b === '') return 0.0;
    if ((strlen($a) === 1 && $a[0] === $b[0]) || (strlen($b) === 1 && $b[0] === $a[0])) return 0.9;
    $max = max(strlen($a), strlen($b));
    return max(0.0, 1.0 - levenshtein($a, $b) / $max);
}

/**
 * How well do the names on the ID agree with the registered name?
 * Every REGISTERED token must find a partner on the ID (extra middle names on the ID are fine).
 * Order-independent. Also tries the "all tokens glued" form so "DELACRUZ" ~ "DELA CRUZ".
 *
 * @return array{score:float, matched:array<string,string>, detail:string}
 */
function id_name_similarity(string $registered, string $onId): array
{
    $reg = id_name_tokens($registered);
    $id  = id_name_tokens($onId);
    if (!$reg || !$id) {
        return ['score' => 0.0, 'matched' => [], 'detail' => 'one of the names is empty'];
    }

    $score = static function (array $reg, array $id): array {
        $used = [];
        $total = 0.0;
        $matched = [];
        foreach ($reg as $r) {
            $best = 0.0; $bestJ = -1;
            foreach ($id as $j => $t) {
                if (isset($used[$j])) continue;
                $s = id_token_similarity($r, $t);
                if ($s > $best) { $best = $s; $bestJ = $j; }
            }
            if ($bestJ >= 0) { $used[$bestJ] = true; $matched[$r] = $id[$bestJ]; }
            $total += $best;
        }
        return [$total / count($reg), $matched];
    };

    [$s1, $m1] = $score($reg, $id);
    // glued comparison handles split/joined surnames ("DELA CRUZ" vs "DELACRUZ", "SAN JOSE" vs "SANJOSE")
    $glueReg = [implode('', $reg)];
    $glueId  = [implode('', $id)];
    $sGlue   = id_token_similarity($glueReg[0], $glueId[0]);
    $best    = max($s1, $sGlue);
    $detail  = 'token score ' . number_format($s1, 2) . ', joined-string score ' . number_format($sGlue, 2);
    return ['score' => round($best, 3), 'matched' => $m1, 'detail' => $detail];
}

/**
 * Layout-agnostic check for raw OCR text: how many registered name tokens (len >= 2) appear in it,
 * allowing 1 typo for tokens of 5+ letters (OCR noise)?
 *
 * @return array{score:float, found:string[], missing:string[]}
 */
function id_name_in_text(string $registered, string $ocrText): array
{
    $reg = array_values(array_filter(id_name_tokens($registered), static fn(string $t): bool => strlen($t) >= 2));
    $words = id_name_tokens($ocrText);
    $words = array_values(array_unique($words));
    $glued = implode('', $words);
    $found = $missing = [];
    foreach ($reg as $r) {
        $hit = false;
        foreach ($words as $w) {
            $d = levenshtein($r, $w);
            if ($d === 0 || (strlen($r) >= 5 && $d <= 1)) { $hit = true; break; }
        }
        if (!$hit && strlen($r) >= 4 && str_contains($glued, $r)) $hit = true;   // run-together OCR output
        $hit ? $found[] = $r : $missing[] = $r;
    }
    $score = $reg ? count($found) / count($reg) : 0.0;
    return ['score' => round($score, 3), 'found' => $found, 'missing' => $missing];
}
