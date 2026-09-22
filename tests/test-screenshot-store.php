<?php
/**
 * Storing the screenshot Lighthouse captured.
 *
 * The data URI comes from a third-party API and is written to disk, so the decoding path is
 * the interesting part: what it accepts, what it refuses, and that nothing in the response
 * can influence the filename.
 */
declare(strict_types=1);
define('ABSPATH','/tmp/'); define('LEADMAP_DIR', dirname(__DIR__) . '/');
define('LEADMAP_VERSION','0.4.2'); define('SECURE_AUTH_KEY','k'); define('AUTH_SALT','s');

$base = sys_get_temp_dir() . '/leadmap-shot-test-' . getmypid();
@mkdir($base, 0777, true);

function __($t,$d=''){return $t;}
function wp_get_upload_dir(){ return ['basedir'=>$GLOBALS['base'],'baseurl'=>'https://site.test/uploads','error'=>false]; }
function trailingslashit($s){ return rtrim($s,'/\\') . '/'; }
function wp_mkdir_p($d){ return is_dir($d) || mkdir($d, 0777, true); }
function wp_delete_file($f){ @unlink($f); }
function add_query_arg($k,$v,$u){ return $u . '?' . $k . '=' . $v; }
$GLOBALS['base'] = $base;

require LEADMAP_DIR.'src/Autoloader.php'; LeadMap\Autoloader::register();
use LeadMap\Triage\Screenshot_Store;

$fail=0;
function check($l,$a,$e){global $fail; $ok=$a===$e; if(!$ok)$fail++;
  printf("%s %-52s got %-22s want %s\n",$ok?'PASS':'FAIL',$l,
    var_export(is_string($a)&&strlen($a)>20?substr($a,0,20).'…':$a,true), var_export($e,true));}

// A real 2x2 PNG, so getimagesizefromstring() agrees with the declared type.
$png = base64_encode(base64_decode(
  'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAFUlEQVR42mP8z8BQz0AEYBxVSF+FABJADveWkH6oAAAAAElFTkSuQmCC'
));
$uri = 'data:image/png;base64,' . $png;

echo "== a genuine capture is stored ==\n";
check('returns a filename',   Screenshot_Store::save(42, 'mobile', $uri), '42-mobile.png');
check('file exists on disk',  is_file($base . '/leadmap-shots/42-mobile.png'), true);
check('directory is closed',  is_file($base . '/leadmap-shots/index.php'), true);
check('desktop is separate',  Screenshot_Store::save(42, 'desktop', $uri), '42-desktop.png');
check('url is produced',      str_contains(Screenshot_Store::url('42-mobile.png'), '/leadmap-shots/42-mobile.png'), true);
check('url is cache-busted',  str_contains(Screenshot_Store::url('42-mobile.png'), '?v='), true);

echo "\n== malformed or hostile payloads are refused ==\n";
foreach ([
  'not a data uri'      => 'https://evil.test/x.png',
  'svg (scriptable)'    => 'data:image/svg+xml;base64,' . base64_encode('<svg onload="alert(1)"/>'),
  'html disguised'      => 'data:image/png;base64,' . base64_encode('<html><script>alert(1)</script></html>'),
  'php disguised'       => 'data:image/png;base64,' . base64_encode('<?php system($_GET[0]); ?>'),
  'wrong declared type' => 'data:image/jpeg;base64,' . $png,   // really a PNG
  'not base64'          => 'data:image/png;base64,@@@@not-base64@@@@',
  'empty payload'       => 'data:image/png;base64,',
  'empty string'        => '',
] as $label => $payload) {
  check('refuses ' . $label, Screenshot_Store::save(43, 'mobile', $payload), '');
}
check('nothing was written', is_file($base . '/leadmap-shots/43-mobile.png'), false);

echo "\n== the response cannot influence the filename ==\n";
check('bad strategy refused', Screenshot_Store::save(42, '../../evil', $uri), '');
check('zero lead refused',    Screenshot_Store::save(0, 'mobile', $uri), '');
check('negative lead refused',Screenshot_Store::save(-5, 'mobile', $uri), '');

echo "\n== path traversal on read and delete ==\n";
foreach ([
  '../../../wp-config.php',
  '..%2F..%2Fwp-config.php',
  '42-mobile.png/../../../etc/passwd',
  '/etc/passwd',
  '42-mobile.php',
  '42-tablet.png',
] as $name) {
  check('url refuses ' . substr($name, 0, 26), Screenshot_Store::url($name), '');
}

echo "\n== oversize payloads are rejected before decoding ==\n";
$huge = 'data:image/png;base64,' . str_repeat('A', 5 * 1024 * 1024);
check('4MB+ refused', Screenshot_Store::save(44, 'mobile', $huge), '');

echo "\n== deletion ==\n";
Screenshot_Store::delete_for_lead(42);
check('mobile gone',  is_file($base . '/leadmap-shots/42-mobile.png'), false);
check('desktop gone', is_file($base . '/leadmap-shots/42-desktop.png'), false);
check('url on missing file', Screenshot_Store::url('42-mobile.png'), '');

// Clean up.
array_map('unlink', glob($base . '/leadmap-shots/*') ?: []);
@rmdir($base . '/leadmap-shots'); @rmdir($base);

echo "\n".($fail?"FAILED: $fail\n":"ALL PASSED\n"); exit($fail?1:0);
