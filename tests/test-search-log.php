<?php
/**
 * The live search log: trimming, level handling, and the progress figure.
 */
declare(strict_types=1);
define('ABSPATH','/tmp/'); define('LEADMAP_DIR', dirname(__DIR__) . '/');
define('LEADMAP_VERSION','0.3.0'); define('SECURE_AUTH_KEY','k'); define('AUTH_SALT','s');

function __($t,$d=''){return $t;}
function wp_json_encode($d){return json_encode($d);}
function mb_substr_polyfill(){}

// A tiny stand-in for the searches table.
class FakeWpdb {
    public string $prefix = 'wp_';
    public array $rows = [];
    public function get_var($q){ return $this->rows[1]['log_json'] ?? null; }
    public function prepare($q, ...$a){ return $q; }
    public function update($t,$data,$where){ $this->rows[$where['id']] = array_merge($this->rows[$where['id']] ?? [], $data); return 1; }
    public function get_row($q){ return (object) ($this->rows[1] ?? []); }
}
$GLOBALS['wpdb'] = new FakeWpdb();
$GLOBALS['wpdb']->rows[1] = ['id'=>1,'log_json'=>null,'status'=>'running','results_found'=>0,'max_results'=>60];

require LEADMAP_DIR.'src/Autoloader.php'; LeadMap\Autoloader::register();
use LeadMap\Search\Search_Repository;

$fail=0;
function check($l,$a,$e){global $fail; $ok=$a===$e; if(!$ok)$fail++;
  printf("%s %-48s got %-12s want %s\n",$ok?'PASS':'FAIL',$l,var_export($a,true),var_export($e,true));}

function lines(): array {
    $json = $GLOBALS['wpdb']->rows[1]['log_json'] ?? '[]';
    return json_decode((string) $json, true) ?: [];
}

echo "== appending ==\n";
Search_Repository::log(1, 'Starting search');
check('one line', count(lines()), 1);
check('message kept', lines()[0]['message'], 'Starting search');
check('defaults to info', lines()[0]['level'], 'info');
check('timestamped', lines()[0]['at'] > 0, true);

Search_Repository::log(1, 'Found 19 businesses', 'good');
check('two lines', count(lines()), 2);
check('level recorded', lines()[1]['level'], 'good');

echo "\n== levels are allow-listed ==\n";
foreach (['info','good','warn','error'] as $level) {
    $GLOBALS['wpdb']->rows[1]['log_json'] = null;
    Search_Repository::log(1, 'x', $level);
    check("accepts $level", lines()[0]['level'], $level);
}
$GLOBALS['wpdb']->rows[1]['log_json'] = null;
Search_Repository::log(1, 'x', '<script>alert(1)</script>');
check('rejects anything else', lines()[0]['level'], 'info');

echo "\n== the log cannot grow without bound ==\n";
$GLOBALS['wpdb']->rows[1]['log_json'] = null;
for ($i = 1; $i <= 200; $i++) { Search_Repository::log(1, "line $i"); }
check('trimmed to 60',        count(lines()), 60);
check('keeps the newest',     lines()[59]['message'], 'line 200');
check('drops the oldest',     lines()[0]['message'], 'line 141');

echo "\n== a single message cannot bloat the row ==\n";
$GLOBALS['wpdb']->rows[1]['log_json'] = null;
Search_Repository::log(1, str_repeat('x', 5000));
check('capped at 300 chars', mb_strlen(lines()[0]['message']), 300);

echo "\n== progress percentage ==\n";
$screen = new LeadMap\Admin\Screens\Searches_Screen();
$pct = new ReflectionMethod($screen, 'percent'); $pct->setAccessible(true);
$p = fn(string $status, int $found, int $max) =>
    $pct->invoke($screen, (object)['status'=>$status,'results_found'=>$found,'max_results'=>$max]);

check('running, nothing yet shows movement', $p('running', 0, 60), 5);
check('half way',                            $p('running', 30, 60), 50);
check('capped at 100',                       $p('running', 999, 60), 100);
check('complete is always full',             $p('complete', 19, 60), 100);
check('failed is full too',                  $p('failed', 0, 60), 100);
check('zero cap does not divide by zero',    $p('running', 10, 0), 100);

echo "\n== assets are cache-busted by file contents, not just plugin version ==\n";
// Two different builds once shipped under one version number, so browsers kept a stale
// stylesheet and the modal rendered without its CSS. Asset versions must follow the file.
$menu = file_get_contents(LEADMAP_DIR . 'src/Admin/Menu.php');
check('asset_version helper exists',
      (bool) preg_match('/private static function asset_version/', $menu), true);
check('it uses filemtime',
      (bool) preg_match('/filemtime\\(/', $menu), true);
check('no asset still enqueued on the bare plugin version',
      (bool) preg_match('/assets\\/[a-z-]+\\.(css|js)\x27,\s*\[[^\]]*\],\s*LEADMAP_VERSION/', $menu), false);

echo "\n== the log does not re-render what the server already printed ==\n";
$js = file_get_contents(LEADMAP_DIR . 'src/Admin/assets/search-log.js');
check('rendered counter seeds from the DOM',
      (bool) preg_match('/var rendered = output \\? output\\.querySelectorAll/', $js), true);
check('does not start from zero',
      (bool) preg_match('/var rendered\\s*=\\s*0\\s*;/', $js), false);

echo "\n".($fail?"FAILED: $fail\n":"ALL PASSED\n"); exit($fail?1:0);
