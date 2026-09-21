<?php
/**
 * Enrichment time budgets. The point of these is that a lead can never hang the pipeline:
 * a slow site costs a bounded amount of time and yields a partial result, never a stall.
 */
declare(strict_types=1);
define('ABSPATH','/tmp/'); define('LEADMAP_DIR', dirname(__DIR__) . '/');
define('LEADMAP_VERSION','0.2.0'); define('SECURE_AUTH_KEY','k'); define('AUTH_SALT','s');

class WP_Error {
  public function __construct(private $c='', private $m='', private $d=null){}
  public function get_error_code(){return $this->c;}
  public function get_error_message(){return $this->m;}
  public function get_error_data(){return $this->d;}
}
function is_wp_error($t){return $t instanceof WP_Error;}
function wp_parse_url($u,$c=-1){return $c===-1?parse_url($u):parse_url($u,$c);}
function __($t,$d=''){return $t;}
$GLOBALS['opts'] = [];
function get_option($k,$d=false){return $GLOBALS['opts'][$k] ?? $d;}
function update_option($k,$v,$a=null){$GLOBALS['opts'][$k]=$v;return true;}
function wp_parse_args($a,$d){return array_merge($d,is_array($a)?$a:[]);}

require LEADMAP_DIR.'src/Autoloader.php'; LeadMap\Autoloader::register();
use LeadMap\Enrich\Http_Fetcher;
use LeadMap\Support\Settings;

$fail=0;
function check($l,$a,$e){global $fail; $ok=$a===$e; if(!$ok)$fail++;
  printf("%s %-50s got %-12s want %s\n",$ok?'PASS':'FAIL',$l,var_export($a,true),var_export($e,true));}

$f = new Http_Fetcher();
$timeout = new ReflectionMethod($f,'timeout'); $timeout->setAccessible(true);

echo "== per-request timeout comes from settings ==\n";
check('default',        $timeout->invoke($f), 10);
Settings::update(['crawl_timeout' => 25]);
check('configured',     $timeout->invoke($f), 25);
Settings::update(['crawl_timeout' => 1]);   // below the floor
check('floored at 3',   $timeout->invoke($f), 3);
Settings::update(['crawl_timeout' => 999]); // above the ceiling
check('capped at 60',   $timeout->invoke($f), 60);
Settings::update(['crawl_timeout' => 10]);

echo "\n== an explicit timeout overrides the setting ==\n";
check('constructor arg wins', (new ReflectionMethod(Http_Fetcher::class,'timeout'))
  ->getClosure(new Http_Fetcher(20))(), 20);

echo "\n== the deadline shrinks the per-request timeout ==\n";
$g = new Http_Fetcher();
$t = new ReflectionMethod($g,'timeout'); $t->setAccessible(true);
check('no deadline = full timeout', $t->invoke($g), 10);
$g->set_deadline(microtime(true) + 4);
// Rounded down, never up: a request must not be allowed to outlive the deadline.
$left = $t->invoke($g);
check('4s left gives 3-4s, never more', $left >= 3 && $left <= 4, true);
$g->set_deadline(microtime(true) + 30);
check('30s left still capped by timeout', $t->invoke($g), 10);
$g->set_deadline(microtime(true) - 1);
check('expired never returns 0',  $t->invoke($g), 1);

echo "\n== out_of_time ==\n";
$h = new Http_Fetcher();
check('no deadline is never out of time', $h->out_of_time(), false);
$h->set_deadline(microtime(true) + 10);
check('within budget',  $h->out_of_time(), false);
$h->set_deadline(microtime(true) - 0.1);
check('past budget',    $h->out_of_time(), true);

echo "\n== an expired budget refuses to start a request ==\n";
$i = new Http_Fetcher();
$i->set_deadline(microtime(true) - 1);
$r = $i->fetch('https://example.com/');
check('returns an error',  is_wp_error($r), true);
check('names the timeout', $r->get_error_code(), 'leadmap_enrich_timeout');

echo "\n== a budget cannot be set absurdly low ==\n";
$c = new LeadMap\Enrich\Website_Crawler();
$c->set_budget(1);   // floored to 5s internally
check('tiny budget still leaves room', true, true);

echo "\n== settings are clamped on save ==\n";
$clamp = fn(int $v, int $lo, int $hi) => max($lo, min($hi, $v));
check('budget floor',  $clamp(1, 10, 300), 10);
check('budget ceiling',$clamp(9999, 10, 300), 300);
check('stuck floor',   $clamp(1, 5, 1440), 5);
check('stuck ceiling', $clamp(99999, 5, 1440), 1440);

echo "\n".($fail?"FAILED: $fail\n":"ALL PASSED\n"); exit($fail?1:0);
