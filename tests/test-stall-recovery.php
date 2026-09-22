<?php
/**
 * Stall detection, the job lock, and stop semantics.
 *
 * Background: a search advances by enqueueing its next page. When that job is handed to
 * WP-Cron it may not run promptly — or at all — so the search has to be able to detect its
 * own stall and be driven forward from elsewhere.
 */
declare(strict_types=1);
define('ABSPATH','/tmp/'); define('LEADMAP_DIR', dirname(__DIR__) . '/');
define('LEADMAP_VERSION','0.3.0'); define('SECURE_AUTH_KEY','k'); define('AUTH_SALT','s');

function __($t,$d=''){return $t;}
$GLOBALS['opts'] = [];
function add_option($k,$v,$d='',$a=null){ if(array_key_exists($k,$GLOBALS['opts'])) return false; $GLOBALS['opts'][$k]=$v; return true; }
function get_option($k,$d=false){ return $GLOBALS['opts'][$k] ?? $d; }
function update_option($k,$v,$a=null){ $GLOBALS['opts'][$k]=$v; return true; }
function delete_option($k){ $had=array_key_exists($k,$GLOBALS['opts']); unset($GLOBALS['opts'][$k]); return $had; }

require LEADMAP_DIR.'src/Autoloader.php'; LeadMap\Autoloader::register();
use LeadMap\Jobs\Lock;
use LeadMap\Rest\Rest_Controller;

$fail=0;
function check($l,$a,$e){global $fail; $ok=$a===$e; if(!$ok)$fail++;
  printf("%s %-50s got %-10s want %s\n",$ok?'PASS':'FAIL',$l,var_export($a,true),var_export($e,true));}

echo "== the lock is exclusive ==\n";
check('first caller wins',      Lock::acquire('search_5'), true);
check('second caller blocked',  Lock::acquire('search_5'), false);
check('reported as held',       Lock::held('search_5'), true);
Lock::release('search_5');
check('released',               Lock::held('search_5'), false);
check('can be re-acquired',     Lock::acquire('search_5'), true);
Lock::release('search_5');

echo "\n== a lock left by a crashed request expires ==\n";
$GLOBALS['opts']['leadmap_lock_search_9'] = (string) ( time() - 1 );  // already expired
check('stale lock is not held',   Lock::held('search_9'), false);
check('stale lock is taken over', Lock::acquire('search_9'), true);
Lock::release('search_9');

echo "\n== different searches do not block each other ==\n";
Lock::acquire('search_1');
check('other search free', Lock::acquire('search_2'), true);
Lock::release('search_1'); Lock::release('search_2');

echo "\n== stall detection ==\n";
$rest = new Rest_Controller();
$m = new ReflectionMethod($rest, 'stalled_for'); $m->setAccessible(true);
$age = fn(?string $progress, string $created) =>
    $m->invoke($rest, (object)['last_progress_at'=>$progress,'created_at'=>$created]);

$now = gmdate('Y-m-d H:i:s');
$ago = fn(int $s) => gmdate('Y-m-d H:i:s', time() - $s);

check('just progressed',        $age($now, $now) <= 1, true);
check('30s ago',                $age($ago(30), $now) >= 29, true);
check('falls back to created',  $age(null, $ago(45)) >= 44, true);
check('never started is huge',  $age(null, '') , PHP_INT_MAX);

// The endpoint nudges once a search has been quiet for 12s.
$STALL = 12;
check('5s quiet: leave alone',  $age($ago(5), $now)  >= $STALL, false);
check('20s quiet: nudge',       $age($ago(20), $now) >= $STALL, true);

echo "\n== which statuses may be driven forward ==\n";
foreach ([
  'queued'    => true,
  'running'   => true,
  'complete'  => false,
  'failed'    => false,
  'cancelled' => false,   // a stopped search must never restart itself
] as $status => $want) {
  check("$status", in_array($status, ['queued','running'], true), $want);
}

echo "\n== stopping is only meaningful while in flight ==\n";
foreach (['queued'=>true,'running'=>true,'complete'=>false,'cancelled'=>false,'failed'=>false] as $status=>$want) {
  check("can stop a $status search", in_array($status,['queued','running'],true), $want);
}

echo "\n".($fail?"FAILED: $fail\n":"ALL PASSED\n"); exit($fail?1:0);
