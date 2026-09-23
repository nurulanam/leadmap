<?php
/**
 * The Gemini draft.
 *
 * The prompt is the security boundary here: the model may only refer to values we actually
 * measured. A cold email whose one specific claim is invented is worse than no email — that
 * claim is the thing the recipient can check, and the pitch rests on it being true.
 */
declare(strict_types=1);
define('ABSPATH','/tmp/'); define('LEADMAP_DIR', dirname(__DIR__) . '/');
define('LEADMAP_VERSION','0.6.1'); define('SECURE_AUTH_KEY','k'); define('AUTH_SALT','s');

function __($t,$d=''){return $t;}
function wp_strip_all_tags($s,$b=false){return trim(strip_tags($s));}
$GLOBALS['opts']=[];
function get_option($k,$d=false){return $GLOBALS['opts'][$k] ?? $d;}
function update_option($k,$v,$a=null){$GLOBALS['opts'][$k]=$v;return true;}
function wp_parse_args($a,$d){return array_merge($d,is_array($a)?$a:[]);}
class WP_Error {
  public function __construct(private $c='', private $m='', private $d=null){}
  public function get_error_code(){return $this->c;}
  public function get_error_message(){return $this->m;}
}
function is_wp_error($t){return $t instanceof WP_Error;}

require LEADMAP_DIR.'src/Autoloader.php'; LeadMap\Autoloader::register();
use LeadMap\Ai\Problem_Writer;

$fail=0;
function check($l,$a,$e){global $fail; $ok=$a===$e; if(!$ok)$fail++;
  printf("%s %-54s got %-14s want %s\n",$ok?'PASS':'FAIL',$l,var_export($a,true),var_export($e,true));}
function has($l,$hay,$needle){global $fail; $ok=str_contains($hay,$needle); if(!$ok)$fail++;
  printf("%s %s\n",$ok?'PASS':'FAIL',$l);}
function hasnt($l,$hay,$needle){global $fail; $ok=!str_contains($hay,$needle); if(!$ok)$fail++;
  printf("%s %s\n",$ok?'PASS':'FAIL',$l);}

$w = new Problem_Writer();
$factsM  = new ReflectionMethod($w,'facts');   $factsM->setAccessible(true);
$promptM = new ReflectionMethod($w,'prompt');  $promptM->setAccessible(true);
$systemM = new ReflectionMethod($w,'system');  $systemM->setAccessible(true);
$tidyM   = new ReflectionMethod($w,'tidy');    $tidyM->setAccessible(true);

$lead = (object) ['name'=>'Acme Plumbing','category'=>'plumber','city'=>'New York',
                  'domain'=>'acmeplumbing.com','review_count'=>34,'rating'=>4.3];
$enrichment = [
  'http'=>['status'=>200],
  'speed_mobile'=>['score'=>28,'lcp_ms'=>7400.0],
  'speed_desktop'=>['score'=>71],
  'seo'=>['has_viewport'=>false,'is_https'=>false,'has_description'=>false,'h1_count'=>0,
          'title'=>'Home','copyright_year'=>2013,'image_count'=>10,'images_with_alt'=>2,
          'mixed_content'=>false],
  'tech'=>['cms'=>'WordPress','dated_markers'=>['jquery_1.7.2','table_layout']],
];

$facts  = $factsM->invoke($w, $lead, $enrichment, ['speed','mobile','security'], 'speed');
$prompt = $promptM->invoke($w, $facts);
$system = $systemM->invoke($w);

echo "== every measurement reaches the prompt ==\n";
has('mobile PageSpeed score',   $prompt, '28 out of 100');
has('desktop PageSpeed score',  $prompt, '71 out of 100');
has('largest paint in seconds', $prompt, '7.4 seconds');
has('missing viewport',         $prompt, 'no mobile viewport');
has('plain HTTP',               $prompt, 'plain HTTP');
has('no meta description',      $prompt, 'no meta description');
has('no H1',                    $prompt, 'no main heading');
has('their page title',         $prompt, '"Home"');
has('alt text shortfall',       $prompt, '8 of 10 images');
has('stale copyright',          $prompt, 'copyright still reads 2013');
has('old jQuery',               $prompt, 'jQuery 1.7.2');
has('table layout',             $prompt, 'HTML tables');
has('the platform',             $prompt, 'built on WordPress');
has('business name',            $prompt, 'Acme Plumbing');
has('review count',             $prompt, '34 reviews');
has('the primary issue',        $prompt, 'PRIMARY ISSUE TO LEAD WITH: Slow');

echo "\n== nothing unmeasured is offered ==\n";
// These would be plausible things to say, and are exactly what must not be suggested.
hasnt('no invented traffic figure',  $prompt, 'visitors');
hasnt('no invented revenue',         $prompt, 'revenue');
hasnt('no competitor claim',         $prompt, 'competitor');
hasnt('no ranking claim',            $prompt, 'ranking');

echo "\n== the instructions forbid embellishment ==\n";
has('only the given facts',     $system, 'Use ONLY the facts given');
has('never invent a number',    $system, 'Never invent or estimate a number');
has('silence on absent topics', $system, 'do not mention that topic at all');
has('no promises',              $system, 'Do not promise results');
has('no exaggeration',          $system, 'Do not exaggerate');
has('problem only, no pitch',   $system, 'Only the description of the problem');
has('no markdown',              $system, 'Plain sentences only');

echo "\n== a lead with nothing measured is refused, not guessed at ==\n";
$empty = $w->draft((object)['name'=>'X','category'=>'','city'=>'','domain'=>'','review_count'=>0,'rating'=>0], [], ['speed'], 'speed');
check('returns an error',  is_wp_error($empty), true);
check('says why',          $empty->get_error_code(), 'leadmap_no_evidence');

echo "\n== healthy sites produce no false problems ==\n";
$healthy = $factsM->invoke($w, $lead, [
  'http'=>['status'=>200],
  'speed_mobile'=>['score'=>96],
  'seo'=>['has_viewport'=>true,'is_https'=>true,'has_description'=>true,'h1_count'=>1,
          'image_count'=>4,'images_with_alt'=>4,'copyright_year'=>(int)gmdate('Y')],
  'tech'=>['cms'=>'','dated_markers'=>[]],
], ['design'], 'design');
$clean = implode(' ', $healthy['measured']);
hasnt('no viewport complaint', $clean, 'no mobile viewport');
hasnt('no HTTPS complaint',    $clean, 'plain HTTP');
hasnt('no alt-text complaint', $clean, 'no alt text');
hasnt('no stale copyright',    $clean, 'copyright still reads');
check('only the score is stated', count($healthy['measured']), 1);

echo "\n== broken sites state the failure plainly ==\n";
$dead = $factsM->invoke($w, $lead, ['unreachable'=>true], ['broken'], 'broken');
has('unreachable is stated', implode(' ', $dead['measured']), 'did not respond');
$err = $factsM->invoke($w, $lead, ['http'=>['status'=>500]], ['broken'], 'broken');
has('HTTP error is stated',  implode(' ', $err['measured']), 'HTTP 500 error');

echo "\n== the wrappers models add anyway are stripped ==\n";
check('leading preamble', $tidyM->invoke($w, "Here is the draft: Your site is slow."), 'Your site is slow.');
check('surrounding quotes', $tidyM->invoke($w, '"Your site is slow."'), 'Your site is slow.');
check('smart quotes', $tidyM->invoke($w, "\u{201C}Your site is slow.\u{201D}"), 'Your site is slow.');
check('bullet markers', $tidyM->invoke($w, "- Your site is slow."), 'Your site is slow.');
check('html is stripped', $tidyM->invoke($w, '<p>Your site is <b>slow</b>.</p>'), 'Your site is slow.');
check('plain text untouched', $tidyM->invoke($w, 'Your site is slow.'), 'Your site is slow.');

echo "\n".($fail?"FAILED: $fail\n":"ALL PASSED\n"); exit($fail?1:0);
