<?php
/**
 * Every field on the settings form must be handled when the form is saved.
 *
 * The Gemini API key was rendered, submitted, and silently dropped: the save method simply
 * had no branch for it, so the field looked broken with no error anywhere. A structural check
 * catches that class of bug, which no amount of testing the save path in isolation would.
 */
declare(strict_types=1);
define('LEADMAP_DIR', dirname(__DIR__) . '/');

$screen = file_get_contents(LEADMAP_DIR . 'src/Admin/Screens/Settings_Screen.php');

// The save method, from its signature to the closing brace at the same indent.
$start = strpos($screen, 'private function save(): string {');
$end   = strpos($screen, "\n\t}", $start);
$save  = substr($screen, $start, $end - $start);

// The rendered form, i.e. everything before save().
$form = substr($screen, 0, $start);

preg_match_all('/<input[^>]*\bname="([a-z0-9_]+)(?:\[\])?"/i', $form, $m);
preg_match_all('/<select[^>]*\bname="([a-z0-9_]+)"/i', $form, $m2);
preg_match_all('/<textarea[^>]*\bname="([a-z0-9_]+)"/i', $form, $m3);

$fields = array_values(array_unique(array_merge($m[1], $m2[1], $m3[1])));
sort($fields);

$fail = 0;

echo "== every submitted field is read when saving ==\n";

foreach ($fields as $field) {
    $read = str_contains($save, "'" . $field . "'") || str_contains($save, '"' . $field . '"');

    if (! $read) { $fail++; }

    printf("%s  %s\n", $read ? 'PASS' : 'FAIL', $field);
}

printf("\n     %d fields on the form\n", count($fields));

echo "\n== keys are encrypted, never stored in the clear ==\n";
foreach (['google_api_key', 'gemini_api_key'] as $key) {
    // Find the assignment for this key and confirm it goes through Encryption.
    $ok = (bool) preg_match(
        '/\$values\[\s*[\x27"]' . preg_quote($key, '/') . '[\x27"]\s*\]\s*=\s*Encryption::encrypt/',
        $save
    );

    if (! $ok) { $fail++; }

    printf("%s  %s is encrypted on save\n", $ok ? 'PASS' : 'FAIL', $key);
}

echo "\n== an empty key field keeps the existing key ==\n";
foreach ([['google_api_key', '$submitted_key'], ['gemini_api_key', '$gemini_key']] as [$key, $var]) {
    $ok = (bool) preg_match(
        '/if\s*\(\s*[\x27"]{2}\s*!==\s*' . preg_quote($var, '/') . '/',
        $save
    );

    if (! $ok) { $fail++; }

    printf("%s  %s is only written when something was typed\n", $ok ? 'PASS' : 'FAIL', $key);
}

echo "\n== a key set in wp-config is not overwritten from the form ==\n";
foreach ([['google_api_key', 'Settings::key_is_from_constant'], ['gemini_api_key', 'Gemini_Client::key_is_from_constant']] as [$key, $guard]) {
    $ok = str_contains($save, $guard);

    if (! $ok) { $fail++; }

    printf("%s  %s respects the constant\n", $ok ? 'PASS' : 'FAIL', $key);
}

echo "\n" . ($fail ? "FAILED: $fail\n" : "ALL PASSED\n");
exit($fail ? 1 : 0);
