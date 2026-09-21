<?php
/**
 * The slot-based rate limiter that keeps PageSpeed requests under Google's ceiling.
 */
declare(strict_types=1);
define('ABSPATH','/tmp/'); define('LEADMAP_DIR', dirname(__DIR__) . '/');
define('LEADMAP_VERSION','0.2.0'); define('SECURE_AUTH_KEY','k'); define('AUTH_SALT','s');

// In-memory stand-in for the options table.
$GLOBALS['opts'] = [];
function get_option($k,$d=false){ return $GLOBALS['opts'][$k] ?? $d; }
function update_option($k,$v,$a=null){ $GLOBALS['opts'][$k]=$v; return true; }
function delete_option($k){ unset($GLOBALS['opts'][$k]); return true; }
function __($t,$d=''){return $t;}

require LEADMAP_DIR.'src/Autoloader.php'; LeadMap\Autoloader::register();
use LeadMap\Support\Rate_Limiter;

$fail=0;
function check($l,$a,$e){global $fail; $ok=$a===$e; if(!$ok)$fail++;
  printf("%s %-48s got %-10s want %s\n",$ok?'PASS':'FAIL',$l,var_export($a,true),var_export($e,true));}

Rate_Limiter::reset('psi');

echo "== intervals ==\n";
check('4/min  = 15s', Rate_Limiter::interval(4), 15);
check('20/min = 3s',  Rate_Limiter::interval(20), 3);
check('60/min = 1s',  Rate_Limiter::interval(60), 1);
check('zero is clamped, not a division by zero', Rate_Limiter::interval(0), 600);

echo "\n== asking is free, only calling claims a slot ==\n";
check('empty bucket is free',  Rate_Limiter::wait_for('psi'), 0);
check('asking twice is still free', Rate_Limiter::wait_for('psi'), 0);
Rate_Limiter::consume('psi', 4);
check('after a call, wait 15s', Rate_Limiter::wait_for('psi'), 15);
check('asking again does not push it out', Rate_Limiter::wait_for('psi'), 15);

echo "\n== a deferred job cannot inflate the queue ==\n";
// The old bug: each re-check consumed a slot, so a waiting job pushed itself further out
// every time it woke up. Asking 50 times must leave the wait untouched.
for ($i = 0; $i < 50; $i++) { Rate_Limiter::wait_for('psi'); }
check('wait unchanged after 50 checks', Rate_Limiter::wait_for('psi'), 15);

echo "\n== wait never exceeds one interval ==\n";
Rate_Limiter::reset('psi');
$max = 0;
for ($i = 0; $i < 200; $i++) {
    $w = Rate_Limiter::wait_for('psi');
    $max = max($max, $w);
    if ($w === 0) { Rate_Limiter::consume('psi', 4); }
}
check('bounded by the interval', $max <= 15, true);

echo "\n== stale slots do not accumulate ==\n";
Rate_Limiter::reset('psi');
$GLOBALS['opts']['leadmap_rl_psi'] = time() - 3600;  // an hour in the past
check('past slot reads as free', Rate_Limiter::wait_for('psi'), 0);
Rate_Limiter::consume('psi', 4);
check('consume starts from now, not the past', Rate_Limiter::wait_for('psi'), 15);

echo "\n== backing off pushes the whole queue ==\n";
Rate_Limiter::reset('psi');
Rate_Limiter::penalise('psi', 120);
check('waits out the penalty', Rate_Limiter::wait_for('psi') >= 119, true);

echo "\n== buckets are independent ==\n";
Rate_Limiter::reset('a'); Rate_Limiter::reset('b');
Rate_Limiter::consume('a', 4);
check('other bucket unaffected', Rate_Limiter::wait_for('b'), 0);

echo "\n".($fail?"FAILED: $fail\n":"ALL PASSED\n"); exit($fail?1:0);
