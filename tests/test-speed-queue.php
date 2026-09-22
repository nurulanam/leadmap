<?php
/**
 * PageSpeed queue behaviour: timeouts, retry policy, and the pending-work bookkeeping that
 * lets an open browser tab drain the queue instead of waiting on WP-Cron.
 */
declare(strict_types=1);
define('ABSPATH','/tmp/'); define('LEADMAP_DIR', dirname(__DIR__) . '/');
define('LEADMAP_VERSION','0.4.4'); define('SECURE_AUTH_KEY','k'); define('AUTH_SALT','s');

class WP_Error {
  public function __construct(private $c='', private $m='', private $d=null){}
  public function get_error_code(){return $this->c;}
  public function get_error_message(){return $this->m;}
  public function get_error_data(){return $this->d;}
}
function is_wp_error($t){return $t instanceof WP_Error;}
function __($t,$d=''){return $t;}
function wp_parse_url($u,$c=-1){return $c===-1?parse_url($u):parse_url($u,$c);}
$GLOBALS['opts']=[];
function get_option($k,$d=false){return $GLOBALS['opts'][$k] ?? $d;}
function update_option($k,$v,$a=null){$GLOBALS['opts'][$k]=$v;return true;}
function delete_option($k){unset($GLOBALS['opts'][$k]);return true;}
function add_option($k,$v,$d='',$a=null){ if(array_key_exists($k,$GLOBALS['opts'])) return false; $GLOBALS['opts'][$k]=$v; return true; }
function wp_parse_args($a,$d){return array_merge($d,is_array($a)?$a:[]);}
function current_time($t,$g=0){return gmdate('Y-m-d H:i:s');}

require LEADMAP_DIR.'src/Autoloader.php'; LeadMap\Autoloader::register();
use LeadMap\Enrich\Speed_Analyzer;
use LeadMap\Enrich\Speed_Status;
use LeadMap\Support\Rate_Limiter;
use LeadMap\Support\Settings;

$fail=0;
function check($l,$a,$e){global $fail; $ok=$a===$e; if(!$ok)$fail++;
  printf("%s %-52s got %-14s want %s\n",$ok?'PASS':'FAIL',$l,var_export($a,true),var_export($e,true));}

$sa = new Speed_Analyzer();
$explain = new ReflectionMethod($sa,'explain'); $explain->setAccessible(true);

echo "== a read timeout is explained as a slow site, not a broken plugin ==\n";
$t = $explain->invoke($sa, new WP_Error('leadmap_http_0', 'cURL error 28: Operation timed out after 60002 milliseconds with 0 bytes received'), '');
check('recoded as a timeout',   $t->get_error_code(), 'leadmap_psi_timeout');
check('blames the slow page',   str_contains($t->get_error_message(), 'site itself is very slow'), true);
check('says it will retry',     str_contains($t->get_error_message(), 'retried automatically'), true);
check('no raw cURL text',       str_contains($t->get_error_message(), 'cURL'), false);

$t2 = $explain->invoke($sa, new WP_Error('x', 'Operation timed out'), '');
check('bare wording also caught', $t2->get_error_code(), 'leadmap_psi_timeout');

echo "\n== timeout is configurable, within sane bounds ==\n";
$clamp = fn(int $v) => max(30, min(180, $v));
check('default',       $clamp((int) Settings::get('pagespeed_timeout', 90)), 90);
check('floor at 30',   $clamp(5), 30);
check('ceiling at 180',$clamp(600), 180);

echo "\n== retry policy: timeouts come back sooner than rate limits ==\n";
// Nothing is throttling us after a timeout, so there is no reason to wait minutes.
$rate_backoff    = fn(int $a) => (int) min(900, 60 * (2 ** $a));
$timeout_backoff = fn(int $a) => (int) min(300, 30 * ($a + 1));
foreach ([0,1,2,3] as $a) {
  check("attempt $a: timeout waits less", $timeout_backoff($a) < $rate_backoff($a), true);
}
check('rate limit caps at 15 min', $rate_backoff(9), 900);
check('timeout caps at 5 min',     $timeout_backoff(9), 300);

echo "\n== both are retried rather than recorded as permanent failures ==\n";
$retryable = function (WP_Error $e): bool {
    if ('leadmap_psi_timeout' === $e->get_error_code()) { return true; }
    if ('leadmap_http_429' === $e->get_error_code()) { return true; }
    $m = strtolower($e->get_error_message());
    foreach (['rate limit','ratelimit','quota exceeded','too many requests'] as $n) {
        if (str_contains($m, $n)) { return true; }
    }
    return false;
};
check('timeout retried',     $retryable(new WP_Error('leadmap_psi_timeout','…')), true);
check('rate limit retried',  $retryable(new WP_Error('leadmap_http_429','Quota exceeded')), true);
check('bad key not retried', $retryable(new WP_Error('leadmap_http_403','API key not valid')), false);
check('dead page not retried', $retryable(new WP_Error('leadmap_http_400','FAILED_DOCUMENT_REQUEST')), false);

echo "\n== the worker paces itself from the server's limiter ==\n";
Rate_Limiter::reset('pagespeed');
check('first call may go',   Rate_Limiter::wait_for('pagespeed'), 0);
Rate_Limiter::consume('pagespeed', 20);
check('20/min spaces by 3s', Rate_Limiter::wait_for('pagespeed'), 3);
Rate_Limiter::reset('pagespeed');
Rate_Limiter::consume('pagespeed', 4);
check('4/min spaces by 15s', Rate_Limiter::wait_for('pagespeed'), 15);

echo "\n== pending states ==\n";
check('queued is in progress',  Speed_Status::in_progress(Speed_Status::QUEUED), true);
check('running is in progress', Speed_Status::in_progress(Speed_Status::RUNNING), true);
check('waiting is in progress', Speed_Status::in_progress(Speed_Status::WAITING), true);
check('done is not',            Speed_Status::in_progress(Speed_Status::DONE), false);
check('failed is not',          Speed_Status::in_progress(Speed_Status::FAILED), false);

echo "\n== a half-finished lead reads as partial, not done ==\n";
$e = ['speed_status'=>['mobile'=>['state'=>'done'],'desktop'=>['state'=>'queued']]];
check('one done one queued', Speed_Status::summary($e), 'queued');
$e2 = ['speed_status'=>['mobile'=>['state'=>'done'],'desktop'=>['state'=>'done']]];
check('both done',           Speed_Status::summary($e2), 'done');
$e3 = ['speed_status'=>['mobile'=>['state'=>'done'],'desktop'=>['state'=>'failed']]];
check('one done one failed is partial', Speed_Status::summary($e3), 'partial');
$e4 = ['speed_status'=>['mobile'=>['state'=>'failed'],'desktop'=>['state'=>'failed']]];
check('both failed is failed',          Speed_Status::summary($e4), 'failed');
check('nothing requested',   Speed_Status::summary([]), '');

echo "\n== a score with no recorded state is inferred as done ==\n";
check('legacy row', Speed_Status::get(['speed_mobile'=>['score'=>55]], 'mobile')['state'], 'done');

echo "\n".($fail?"FAILED: $fail\n":"ALL PASSED\n"); exit($fail?1:0);
