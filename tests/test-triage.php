<?php
/**
 * Triage verdicts: what combinations are legal, what status each produces, and the
 * auto-triage rules. Triage is a hard gate — a wrong verdict here silently loses a lead.
 */
declare(strict_types=1);
define('ABSPATH','/tmp/'); define('LEADMAP_DIR', dirname(__DIR__) . '/');
define('LEADMAP_VERSION','0.4.0'); define('SECURE_AUTH_KEY','k'); define('AUTH_SALT','s');

function __($t,$d=''){return $t;}
function wp_parse_url($u,$c=-1){return $c===-1?parse_url($u):parse_url($u,$c);}
function sanitize_key($k){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$k));}
function add_query_arg($args,$url){ return $url . '?' . http_build_query($args); }
$GLOBALS['opts']=[];
function get_option($k,$d=false){return $GLOBALS['opts'][$k] ?? $d;}
function update_option($k,$v,$a=null){$GLOBALS['opts'][$k]=$v;return true;}
function wp_parse_args($a,$d){return array_merge($d,is_array($a)?$a:[]);}
// Minimal $wpdb so lead lookups return "not found" rather than fatalling.
class FakeWpdb {
    public string $prefix = 'wp_';
    public function get_row($q){ return null; }
    public function get_var($q){ return 0; }
    public function get_results($q){ return []; }
    public function prepare($q, ...$a){ return $q; }
    public function update($t,$d,$w){ return 1; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

class WP_Error {
  public function __construct(private $c='', private $m='', private $d=null){}
  public function get_error_code(){return $this->c;}
  public function get_error_message(){return $this->m;}
}
function is_wp_error($t){return $t instanceof WP_Error;}

require LEADMAP_DIR.'src/Autoloader.php'; LeadMap\Autoloader::register();
use LeadMap\Triage\Verdicts;
use LeadMap\Triage\Triage_Service;
use LeadMap\Triage\Screenshotter;
use LeadMap\Support\Settings;

$fail=0;
function check($l,$a,$e){global $fail; $ok=$a===$e; if(!$ok)$fail++;
  printf("%s %-50s got %-18s want %s\n",$ok?'PASS':'FAIL',$l,var_export($a,true),var_export($e,true));}

echo "== the vocabulary ==\n";
check('seven pitchable flags', count(Verdicts::FLAGS), 7);
check('three terminal',       count(Verdicts::TERMINAL), 3);
check('outdated is a flag',   Verdicts::is_flag('outdated'), true);
check('skip is terminal',     Verdicts::is_terminal('skip'), true);
check('skip is not a flag',   Verdicts::is_flag('skip'), false);
check('nonsense is neither',  Verdicts::is_flag('banana') || Verdicts::is_terminal('banana'), false);

echo "\n== each verdict maps to the right lead status ==\n";
foreach ([
  'outdated'   => 'triaged',
  'broken'     => 'triaged',
  'not_mobile' => 'triaged',
  'slow'       => 'triaged',
  'no_ssl'     => 'triaged',
  'poor_seo'   => 'triaged',
  'weak_gmb'   => 'triaged',
  'skip'       => 'skipped',
  'no_website' => 'no_website',
  'unsure'     => 'triage_deferred',
] as $verdict => $status) {
  check("$verdict", Verdicts::status_for($verdict), $status);
}

echo "\n== keyboard shortcuts are unique ==\n";
$keys = [];
foreach (Verdicts::flags() as $f) { $keys[] = strtolower($f['key']); }
foreach (Verdicts::terminal() as $t) { $keys[] = strtolower($t['key']); }
check('no duplicate keys', count($keys) === count(array_unique($keys)), true);
check('all single characters', count(array_filter($keys, fn($k) => strlen($k) === 1)), 10);

echo "\n== illegal combinations are refused, not silently coerced ==\n";
// These run against a lead that does not exist, so they must fail on validation first —
// which is exactly what we want to assert: validation happens before any write.
$svc = new Triage_Service();
$err = fn($flags) => $svc->decide(0, $flags);
check('empty verdict',        is_wp_error($err([])), true);
check('unknown verdict',      is_wp_error($err(['banana'])), true);
check('skip + outdated',      $err(['skip','outdated'])->get_error_code(), 'leadmap_mixed_verdict');
check('skip + no_website',    $err(['skip','no_website'])->get_error_code(), 'leadmap_mixed_verdict');
check('unsure + slow',        $err(['unsure','slow'])->get_error_code(), 'leadmap_mixed_verdict');

echo "\n== legal combinations reach the lead lookup ==\n";
// A site can be both outdated and broken; that must pass validation.
check('outdated + broken allowed', $err(['outdated','broken'])->get_error_code(), 'leadmap_lead_missing');
check('all four flags allowed',    $err(Verdicts::FLAGS)->get_error_code(), 'leadmap_lead_missing');
check('single terminal allowed',   $err(['skip'])->get_error_code(), 'leadmap_lead_missing');

echo "\n== auto-triage is off unless asked for ==\n";
$lead = (object) ['website'=>'', 'enrichment_json'=>'{}', 'staleness_score'=>0];
check('disabled by default', $svc->auto_verdict($lead), '');

Settings::update(['auto_triage' => true]);
Settings::flush();

echo "\n== auto-triage decides only the unambiguous cases ==\n";
$mk = fn(array $e, $website='https://a.com', $stale=50) =>
  (object)['website'=>$website,'enrichment_json'=>json_encode($e),'staleness_score'=>$stale];

check('no website',        $svc->auto_verdict($mk([], '')), 'no_website');
check('unreachable',       $svc->auto_verdict($mk(['unreachable'=>true])), 'broken');
check('404 home page',     $svc->auto_verdict($mk(['http'=>['status'=>404]])), 'broken');
check('500 home page',     $svc->auto_verdict($mk(['http'=>['status'=>503]])), 'broken');

$healthy = ['http'=>['status'=>200],'speed_mobile'=>['score'=>96],
            'seo'=>['has_viewport'=>true,'is_https'=>true,'mixed_content'=>false]];
check('perfectly healthy is skipped', $svc->auto_verdict($mk($healthy, 'https://a.com', 0)), 'skip');

echo "\n== anything ambiguous stays for a human ==\n";
// An automatic skip is the one mistake that loses a real lead, so the bar is deliberately high.
check('fast but not mobile',  $svc->auto_verdict($mk(
  ['http'=>['status'=>200],'speed_mobile'=>['score'=>95],'seo'=>['has_viewport'=>false,'is_https'=>true]], 'https://a.com', 0)), '');
check('fast but no HTTPS',    $svc->auto_verdict($mk(
  ['http'=>['status'=>200],'speed_mobile'=>['score'=>95],'seo'=>['has_viewport'=>true,'is_https'=>false]], 'https://a.com', 0)), '');
check('fast but stale',       $svc->auto_verdict($mk($healthy, 'https://a.com', 35)), '');
check('healthy but slow',     $svc->auto_verdict($mk(
  ['http'=>['status'=>200],'speed_mobile'=>['score'=>62],'seo'=>['has_viewport'=>true,'is_https'=>true]], 'https://a.com', 0)), '');
check('no PSI score yet',     $svc->auto_verdict($mk(
  ['http'=>['status'=>200],'seo'=>['has_viewport'=>true,'is_https'=>true]], 'https://a.com', 0)), '');
check('mixed content',        $svc->auto_verdict($mk(
  ['http'=>['status'=>200],'speed_mobile'=>['score'=>95],
   'seo'=>['has_viewport'=>true,'is_https'=>true,'mixed_content'=>true]], 'https://a.com', 0)), '');

echo "\n== screenshots come only from PageSpeed now ==\n";
$shots = new Screenshotter();
$lead  = (object) ['website' => 'https://example.com/', 'review_count' => 40];

$none = $shots->for_lead($lead, []);
check('nothing captured yet',   $none['url'], '');
check('reported as pending',    $none['pending'], true);
check('not treated as real',    $none['real'], false);
check('has_any is false',       $shots->has_any([]), false);

$failed = $shots->for_lead($lead, ['speed_status' => ['desktop' => ['state' => 'failed']]]);
check('a failed run is not pending', $failed['pending'], false);

check('has_any sees a stored capture', $shots->has_any(['shot_desktop' => '7-desktop.jpg']), true);

echo "\n== suggested flags follow the evidence ==\n";
$suggest = fn(array $e, int $reviews = 40) =>
    Verdicts::suggest((object) ['review_count' => $reviews], $e);

check('dead site suggests broken',
      in_array('broken', $suggest(['unreachable' => true]), true), true);
check('404 suggests broken',
      in_array('broken', $suggest(['http' => ['status' => 404]]), true), true);
check('no HTTPS suggests no_ssl',
      in_array('no_ssl', $suggest(['seo' => ['is_https' => false]]), true), true);
check('no viewport suggests not_mobile',
      in_array('not_mobile', $suggest(['seo' => ['has_viewport' => false]]), true), true);
check('low PSI suggests slow',
      in_array('slow', $suggest(['speed_mobile' => ['score' => 31]]), true), true);
check('dated markers suggest outdated',
      in_array('outdated', $suggest(['tech' => ['dated_markers' => ['flash']]]), true), true);
check('big SEO gap suggests poor_seo',
      in_array('poor_seo', $suggest(['seo' => ['gap_score' => 55]]), true), true);
check('few reviews suggest weak_gmb',
      in_array('weak_gmb', $suggest([], 3), true), true);
check('plenty of reviews do not',
      in_array('weak_gmb', $suggest([], 250), true), false);

echo "\n== a healthy site suggests nothing ==\n";
$healthy = ['http' => ['status' => 200], 'speed_mobile' => ['score' => 95],
            'seo' => ['is_https' => true, 'has_viewport' => true, 'gap_score' => 4],
            'tech' => ['dated_markers' => []]];
check('no flags suggested', $suggest($healthy, 120), []);

echo "\n== suggestions are only ever real flags ==\n";
$all = $suggest(['unreachable' => true, 'seo' => ['is_https' => false, 'has_viewport' => false, 'gap_score' => 80],
                 'speed_mobile' => ['score' => 5], 'tech' => ['dated_markers' => ['flash', 'font_tag']]], 1);
check('every suggestion is a known flag',
      array_diff($all, Verdicts::FLAGS), []);
check('no duplicates', count($all), count(array_unique($all)));
check('a bad site suggests several', count($all) >= 5, true);

echo "\n".($fail?"FAILED: $fail\n":"ALL PASSED\n"); exit($fail?1:0);
