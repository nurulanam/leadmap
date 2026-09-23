<?php
/**
 * The audit stage: what a valid audit requires, how the fit score is composed, and how
 * triage verdicts carry across into the form.
 */
declare(strict_types=1);
define('ABSPATH','/tmp/'); define('LEADMAP_DIR', dirname(__DIR__) . '/');
define('LEADMAP_VERSION','0.6.0'); define('SECURE_AUTH_KEY','k'); define('AUTH_SALT','s');

function __($t,$d=''){return $t;}
function sanitize_key($k){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$k));}
function wp_json_encode($d){return json_encode($d);}
function current_time($t,$g=0){return gmdate('Y-m-d H:i:s');}
function get_current_user_id(){return 1;}
function mb_strlen_safe($s){return mb_strlen($s);}
class WP_Error {
  public function __construct(private $c='', private $m='', private $d=null){}
  public function get_error_code(){return $this->c;}
  public function get_error_message(){return $this->m;}
}
function is_wp_error($t){return $t instanceof WP_Error;}
class FakeWpdb {
  public string $prefix='wp_';
  public function get_row($q){ return null; }
  public function get_var($q){ return 0; }
  public function get_results($q){ return []; }
  public function prepare($q,...$a){ return $q; }
  public function insert($t,$d){ return 1; }
  public function update($t,$d,$w){ return 1; }
  public function delete($t,$w,$f=null){ return 1; }
}
$GLOBALS['wpdb'] = new FakeWpdb();

require LEADMAP_DIR.'src/Autoloader.php'; LeadMap\Autoloader::register();
use LeadMap\Audit\{Issue_Tags, Fit_Scorer, Audit_Service};

$fail=0;
function check($l,$a,$e){global $fail; $ok=$a===$e; if(!$ok)$fail++;
  printf("%s %-52s got %-20s want %s\n",$ok?'PASS':'FAIL',$l,
    var_export(is_array($a)?implode(',',$a):$a,true), var_export(is_array($e)?implode(',',$e):$e,true));}

echo "== the tag vocabulary ==\n";
check('ten issue tags',    count(Issue_Tags::all()), 10);
check('design exists',     Issue_Tags::exists('design'), true);
check('nonsense does not', Issue_Tags::exists('vibes'), false);
$keys = array_map(fn($m) => $m['key'], Issue_Tags::all());
check('shortcut keys unique', count($keys) === count(array_unique($keys)), true);

echo "\n== triage verdicts carry into the audit form ==\n";
check('outdated becomes design',    Issue_Tags::from_triage(['outdated']), ['design']);
check('slow becomes speed',         Issue_Tags::from_triage(['slow']), ['speed']);
check('no_ssl becomes security',    Issue_Tags::from_triage(['no_ssl']), ['security']);
check('several carry across',       Issue_Tags::from_triage(['outdated','slow','weak_gmb']), ['design','speed','listing']);
check('unknown flags are dropped',  Issue_Tags::from_triage(['banana']), []);
// Tags a machine cannot judge must never be pre-filled.
check('content is never seeded',    in_array('content', Issue_Tags::from_triage(['outdated','slow','broken','no_ssl','poor_seo','weak_gmb','not_mobile']), true), false);
check('conversion is never seeded', in_array('conversion', Issue_Tags::from_triage(['outdated','slow']), true), false);

echo "\n== a valid audit is required before a lead can be contacted ==\n";
$svc = new Audit_Service();
$note = 'The booking button sits below three screens of text and the page takes nine seconds on a phone.';

$err = fn(array $in) => $svc->save(1, $in);
check('no tags refused',
      $err(['outcome'=>'audited','issue_tags'=>[],'primary_issue'=>'speed','problem_notes'=>$note])->get_error_code(),
      'leadmap_no_tags');
check('no primary refused',
      $err(['outcome'=>'audited','issue_tags'=>['speed'],'primary_issue'=>'','problem_notes'=>$note])->get_error_code(),
      'leadmap_no_primary');
check('primary must be one of the tags',
      $err(['outcome'=>'audited','issue_tags'=>['speed'],'primary_issue'=>'design','problem_notes'=>$note])->get_error_code(),
      'leadmap_primary_untagged');
check('a throwaway note is refused',
      $err(['outcome'=>'audited','issue_tags'=>['speed'],'primary_issue'=>'speed','problem_notes'=>'slow'])->get_error_code(),
      'leadmap_thin_note');
check('an empty note is refused',
      $err(['outcome'=>'audited','issue_tags'=>['speed'],'primary_issue'=>'speed','problem_notes'=>''])->get_error_code(),
      'leadmap_thin_note');
check('a real audit passes validation',
      $err(['outcome'=>'audited','issue_tags'=>['speed'],'primary_issue'=>'speed','problem_notes'=>$note])->get_error_code(),
      'leadmap_lead_missing');
check('invalid tags are stripped, not accepted',
      $err(['outcome'=>'audited','issue_tags'=>['vibes'],'primary_issue'=>'speed','problem_notes'=>$note])->get_error_code(),
      'leadmap_no_tags');

echo "\n== not-a-fit skips the requirements ==\n";
// A lead leaving the pipeline needs no problem statement.
check('no tags or note needed',
      $err(['outcome'=>'not_a_fit','issue_tags'=>[],'primary_issue'=>'','problem_notes'=>''])->get_error_code(),
      'leadmap_lead_missing');

echo "\n== fit measures the prospect, not the damage ==\n";
$scorer = new Fit_Scorer();
$lead = fn(array $o = []) => (object) array_merge([
  'staleness_score'=>60,'email'=>'owner@acme.com','email_confidence'=>90,'phone'=>'+12125550147',
  'review_count'=>45,'rating'=>4.6,'domain'=>'acme.com',
], $o);

$good = $scorer->score($lead(), [])['score'];
$noEmail = $scorer->score($lead(['email'=>'','email_confidence'=>0]), [])['score'];
$dormant = $scorer->score($lead(['review_count'=>0,'rating'=>0]), [])['score'];
$chain   = $scorer->score($lead(['review_count'=>2400]), [])['score'];
$pristine = $scorer->score($lead(['staleness_score'=>2]), [])['score'];

printf("     reachable %d · no email %d · dormant %d · chain %d · nothing wrong %d\n",
       $good, $noEmail, $dormant, $chain, $pristine);

check('unreachable scores lower',        $noEmail < $good, true);
check('dormant scores lower',            $dormant < $good, true);
check('a likely chain scores lower',     $chain < $good, true);
check('a healthy site is a poor prospect', $pristine < $good, true);
check('a good prospect scores well',     $good >= 70, true);

echo "\n== reachability is weighted heavily, because the pipeline ends in an email ==\n";
$f = $scorer->score($lead(['email'=>'','email_confidence'=>0]), [])['factors'];
check('phone-only is still poor',  $f['reachability']['score'] <= 25, true);
$f2 = $scorer->score($lead(['email'=>'','email_confidence'=>0,'phone'=>'']), [])['factors'];
check('no contact at all is zero', $f2['reachability']['score'], 0);
$f3 = $scorer->score($lead(['email_confidence'=>45]), [])['factors'];
check('a weak email scores weakly', $f3['reachability']['score'], 45);

echo "\n== every factor is explained ==\n";
$all = $scorer->score($lead(), []);
foreach (array_keys(Fit_Scorer::labels()) as $key) {
  check("$key has a summary", '' !== ($all['factors'][$key]['summary'] ?? ''), true);
}
check('weights total 100', array_sum(array_column($all['factors'], 'weight')), 100);

echo "\n".($fail?"FAILED: $fail\n":"ALL PASSED\n"); exit($fail?1:0);
