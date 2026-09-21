<?php
/**
 * The searchText request body.
 *
 * Regression guard for the paging contract: Google rejects a paging request whose body does
 * not repeat every parameter of the initial request, with "Empty text_query. Request
 * parameters for paging requests must match the initial SearchText request."
 */
declare(strict_types=1);
define('ABSPATH','/tmp/'); define('LEADMAP_DIR', dirname(__DIR__) . '/');
define('LEADMAP_VERSION','0.1.0'); define('SECURE_AUTH_KEY','k'); define('AUTH_SALT','s');

function wp_parse_url($u,$c=-1){return parse_url($u,$c);}
function __($t,$d=''){return $t;}
function apply_filters($h,$v,...$a){return $v;}
function do_action($h,...$a){}
function get_option($k,$d=false){return $d;}

require LEADMAP_DIR.'src/Autoloader.php'; LeadMap\Autoloader::register();

use LeadMap\Search\Search_Query;
use LeadMap\Providers\Google_Places_Provider;

$fail = 0;
function check($l,$a,$e){global $fail; $ok=$a===$e; if(!$ok)$fail++;
  printf("%s %-44s got %-26s want %s\n",$ok?'PASS':'FAIL',$l,
    var_export(is_array($a)?json_encode($a):$a,true), var_export(is_array($e)?json_encode($e):$e,true));}

$provider = new Google_Places_Provider();
$build = new ReflectionMethod($provider, 'build_body');
$build->setAccessible(true);

$first  = new Search_Query('plumber','','10002',5000,60,'US','en',40.7157,-73.9879,null);
$second = $first->with_page_token('CmRaAAAAtoken');

$a = $build->invoke($provider, $first);
$b = $build->invoke($provider, $second);

echo "== initial request ==\n";
check('textQuery',    $a['textQuery'], 'plumber in 10002');
check('pageSize',     $a['pageSize'], 20);
check('regionCode',   $a['regionCode'], 'US');
check('has bias',     isset($a['locationBias']), true);
check('bias radius',  $a['locationBias']['circle']['radius'], 5000.0);
check('no token',     isset($a['pageToken']), false);

echo "\n== paging request must repeat every initial parameter ==\n";
check('token present',    $b['pageToken'], 'CmRaAAAAtoken');
check('textQuery kept',   $b['textQuery'], $a['textQuery']);
check('pageSize kept',    $b['pageSize'], $a['pageSize']);
check('languageCode kept',$b['languageCode'], $a['languageCode']);
check('regionCode kept',  $b['regionCode'], $a['regionCode']);
check('locationBias kept',$b['locationBias'], $a['locationBias']);

// The whole body minus the token must be byte-identical to the initial request.
$stripped = $b; unset($stripped['pageToken']);
check('body identical bar token', $stripped, $a);

echo "\n== guards ==\n";
$small = $build->invoke($provider, new Search_Query('cafe','NYC','',5000,1));
check('pageSize floor 1',   $small['pageSize'], 1);
$huge = $build->invoke($provider, new Search_Query('cafe','NYC','',999999,60,'US','en',1.0,2.0));
check('radius capped 50km', $huge['locationBias']['circle']['radius'], 50000.0);
$nobias = $build->invoke($provider, new Search_Query('cafe','NYC'));
check('no bias without centre', isset($nobias['locationBias']), false);

echo "\n".($fail?"FAILED: $fail\n":"ALL PASSED\n"); exit($fail?1:0);
