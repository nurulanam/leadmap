<?php
declare(strict_types=1);
define('ABSPATH', '/tmp/');
define('LEADMAP_DIR', dirname(__DIR__) . '/');
define('LEADMAP_VERSION', '0.1.0');
define('SECURE_AUTH_KEY', 'test-secure-key-abc');
define('AUTH_SALT', 'test-auth-salt-xyz');

// Minimal WP stubs for the pieces under test.
function wp_parse_url($url, $c = -1) { return parse_url($url, $c); }
function wp_json_encode($d) { return json_encode($d); }
function __($t, $d = '') { return $t; }
function apply_filters($h, $v, ...$a) { return $v; }
function do_action($h, ...$a) {}
function get_option($k, $d = false) { return $d; }

require LEADMAP_DIR . 'src/Autoloader.php';
LeadMap\Autoloader::register();

use LeadMap\Support\Normalize;
use LeadMap\Support\Encryption;
use LeadMap\Search\Search_Query;

$fail = 0;
function check(string $label, $actual, $expected) {
    global $fail;
    $ok = $actual === $expected;
    if (!$ok) { $fail++; }
    printf("%s %-52s got %-28s want %s\n",
        $ok ? 'PASS' : 'FAIL', $label,
        var_export($actual, true), var_export($expected, true));
}

echo "== Normalize::domain ==\n";
check('https + www',        Normalize::domain('https://www.Acme-Dental.com/contact'), 'acme-dental.com');
check('no scheme',          Normalize::domain('acmedental.com'), 'acmedental.com');
check('http subdomain',     Normalize::domain('http://shop.acme.co.uk/x?y=1'), 'shop.acme.co.uk');
check('empty',              Normalize::domain(''), '');
check('garbage',            Normalize::domain('not a url'), '');
check('no TLD',             Normalize::domain('localhost'), '');
check('trailing dot space', Normalize::domain('  https://Acme.COM  '), 'acme.com');
check('hyphen TLD reject',  Normalize::domain('http://acme.-com'), '');
check('IP address',         Normalize::domain('http://192.168.1.1'), '');

echo "\n== Normalize::is_generic_host ==\n";
check('facebook page',      Normalize::is_generic_host('facebook.com'), true);
check('wix subdomain',      Normalize::is_generic_host('joes.wixsite.com'), true);
check('real business',      Normalize::is_generic_host('acmedental.com'), false);
check('empty is generic',   Normalize::is_generic_host(''), true);

echo "\n== Normalize::phone_e164 ==\n";
check('US formatted',       Normalize::phone_e164('(212) 555-0147', 'US'), '+12125550147');
check('US with 1',          Normalize::phone_e164('1-212-555-0147', 'US'), '+12125550147');
check('already intl',       Normalize::phone_e164('+44 20 7946 0958', 'GB'), '+442079460958');
check('dedupe match',       Normalize::phone_e164('212.555.0147', 'US') === Normalize::phone_e164('+1 212 555 0147', 'US'), true);
check('empty',              Normalize::phone_e164('', 'US'), '');

echo "\n== Encryption round trip ==\n";
$key = 'AIzaSyD-ExampleKeyMaterial-1234567890';
$enc = Encryption::encrypt($key);
check('encrypted is prefixed', str_starts_with($enc, 'lm1:'), true);
check('ciphertext hides key',  str_contains($enc, $key), false);
check('decrypts to original',  Encryption::decrypt($enc), $key);
check('two encryptions differ', Encryption::encrypt($key) !== Encryption::encrypt($key), true);
check('mask shows last 4',     Encryption::mask($key), '••••••••7890');
check('tamper returns empty',   Encryption::decrypt('lm1:' . base64_encode(random_bytes(40))), '');

echo "\n== Search_Query ==\n";
$q = new Search_Query('dentists', 'New York, NY', '10001', 5000, 60);
check('text query',         $q->text(), 'dentists in New York, NY 10001');
check('round trip',         Search_Query::from_array($q->to_array())->text(), $q->text());
check('immutable center',   $q->with_center(40.75, -73.99)->lat, 40.75);
check('original unchanged', $q->lat, null);
$industry_only = new Search_Query('plumbers');
check('no location',        $industry_only->text(), 'plumbers');

echo "\n" . ($fail ? "FAILED: {$fail}\n" : "ALL PASSED\n");
exit($fail ? 1 : 0);
