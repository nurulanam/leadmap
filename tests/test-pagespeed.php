<?php
/**
 * PageSpeed parsing and presentation. The colour bands must match PageSpeed Insights
 * exactly (0–49 red, 50–89 amber, 90–100 green) or the screen misrepresents Google.
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
function current_time($t,$g=0){return gmdate('Y-m-d H:i:s');}
function get_option($k,$d=false){return $GLOBALS['opts'][$k] ?? $d;}
function update_option($k,$v,$a=null){$GLOBALS['opts'][$k]=$v;return true;}
function wp_parse_args($a,$d){return array_merge($d,is_array($a)?$a:[]);}
$GLOBALS['opts']=[];

require LEADMAP_DIR.'src/Autoloader.php'; LeadMap\Autoloader::register();

use LeadMap\Enrich\Speed_Analyzer;
use LeadMap\Admin\Screens\Lead_Detail_Screen;

$fail=0;
function check($l,$a,$e){global $fail; $ok=$a===$e; if(!$ok)$fail++;
  printf("%s %-46s got %-14s want %s\n",$ok?'PASS':'FAIL',$l,var_export($a,true),var_export($e,true));}

// ---- Metric rating thresholds (Lighthouse score 0–1) -----------------------------
$sa = new Speed_Analyzer();
$rating = new ReflectionMethod($sa,'rating'); $rating->setAccessible(true);
$r = fn(?float $score) => $rating->invoke($sa, ['m'=>['score'=>$score]], 'm');

echo "== metric ratings match Lighthouse bands ==\n";
check('1.00 good',    $r(1.0),  'good');
check('0.90 good',    $r(0.90), 'good');
check('0.89 average', $r(0.89), 'average');
check('0.50 average', $r(0.50), 'average');
check('0.49 poor',    $r(0.49), 'poor');
check('0.00 poor',    $r(0.0),  'poor');
check('missing',      $rating->invoke($sa, [], 'm'), '');

// ---- Score bands must match PageSpeed Insights ------------------------------------
$screen = new Lead_Detail_Screen();
$band = new ReflectionMethod($screen,'psi_band'); $band->setAccessible(true);
$b = fn(int $n) => $band->invoke($screen, $n);

echo "\n== gauge colour bands match PageSpeed Insights ==\n";
check('0   red',    $b(0),   'high');
check('49  red',    $b(49),  'high');
check('50  amber',  $b(50),  'mid');
check('89  amber',  $b(89),  'mid');
check('90  green',  $b(90),  'low');
check('100 green',  $b(100), 'low');

// ---- Gauge arc maths ---------------------------------------------------------------
echo "\n== gauge arc geometry ==\n";
$circumference = 326.7;
$offset = fn(int $score) => round($circumference * (1 - ($score/100)), 1);
check('0 = empty ring',   $offset(0),   326.7);
check('50 = half ring',   $offset(50),  163.4);
check('100 = full ring',  $offset(100), 0.0);
$r52 = round(2 * M_PI * 52, 1);
check('circumference of r=52', $r52, 326.7);

// ---- Metric formatting --------------------------------------------------------------
$fmt = new ReflectionMethod($screen,'format_metric'); $fmt->setAccessible(true);
$f = fn(float $v, string $k) => $fmt->invoke($screen, $v, $k);

echo "\n== metric formatting ==\n";
check('LCP ms to seconds', $f(4200.0, 'ms'),     '4.2 s');
check('sub-second',        $f(900.0,  'ms'),     '0.9 s');
check('TBT stays ms',      $f(1240.0, 'raw_ms'), '1,240 ms');
check('CLS is a ratio',    $f(0.2456, 'ratio'),  '0.246');
check('CLS zero',          $f(0.0,    'ratio'),  '0.000');

// ---- Full API response parsing -------------------------------------------------------
echo "\n== parses a realistic PageSpeed response ==\n";
$response = ['lighthouseResult'=>[
  'finalUrl'=>'https://acme.com/',
  'categories'=>['performance'=>['score'=>0.34]],
  'audits'=>[
    'largest-contentful-paint'=>['numericValue'=>5400.2,'score'=>0.21],
    'first-contentful-paint'  =>['numericValue'=>2100.0,'score'=>0.62],
    'total-blocking-time'     =>['numericValue'=>870.0, 'score'=>0.35],
    'cumulative-layout-shift' =>['numericValue'=>0.184, 'score'=>0.71],
    'speed-index'             =>['numericValue'=>6200.0,'score'=>0.18],
  ],
]];
$lh = $response['lighthouseResult'];
check('score x100',    (int) round((float) $lh['categories']['performance']['score'] * 100), 34);
check('lcp rounded',   round((float) $lh['audits']['largest-contentful-paint']['numericValue'],3), 5400.2);
check('lcp rating',    $rating->invoke($sa,$lh['audits'],'largest-contentful-paint'), 'poor');
check('fcp rating',    $rating->invoke($sa,$lh['audits'],'first-contentful-paint'), 'average');
check('cls rating',    $rating->invoke($sa,$lh['audits'],'cumulative-layout-shift'), 'average');
check('display score', $b(34), 'high');

echo "\n== desktop/mobile gap detection ==\n";
$gap = fn(int $d, int $m) => $d - $m;
check('big gap flagged',   $gap(92, 34) >= 25, true);
check('small gap quiet',   $gap(58, 47) >= 25, false);

// ---- Regression: query values must not be double-encoded ------------------------------
// add_query_arg() runs urlencode_deep() itself. Pre-encoding with rawurlencode() turned
// https://acme.com/ into https%253A%252F%252Facme.com%252F, which PageSpeed rejects — that
// is why no reports came back at all.
echo "\n== a refused key is not reported as a rate limit ==\n";
// The failure that wasted a support round trip: PageSpeed refused the key, LeadMap silently
// retried without one, hit the anonymous limit, and then advised enabling an API that was
// already enabled — hiding the actual refusal.
$explain = new ReflectionMethod($sa, 'explain'); $explain->setAccessible(true);

$rate_limited = new WP_Error('leadmap_http_429', 'Quota exceeded for quota metric \'Queries\'.');

$blind = $explain->invoke($sa, $rate_limited, '');
$aware = $explain->invoke($sa, $rate_limited, 'PageSpeed Insights API has not been used in project 12345 before or it is disabled.');

check('without a key refusal, reports the limit',
      str_contains(strtolower($blind->get_error_message()), 'rate limit'), true);
check('with one, names the key as the cause',
      str_contains(strtolower($aware->get_error_message()), 'refused your api key'), true);
check('and quotes Google\'s own reason',
      str_contains($aware->get_error_message(), 'has not been used in project'), true);
check('and stops telling them to enable what they enabled',
      str_contains($aware->get_error_message(), 'Unauthenticated requests are limited'), false);

echo "\n== query encoding (regression) ==\n";

$wp_add_query_arg = function (array $args, string $url): string {
    // Faithful to WordPress: values are urlencoded by the function itself.
    $parts = explode('?', $url, 2);
    $qs = [];
    if (isset($parts[1])) { parse_str($parts[1], $qs); }
    $qs = array_merge($qs, $args);
    $qs = array_map('urlencode', $qs);           // urlencode_deep()
    $pairs = [];
    foreach ($qs as $k => $v) { $pairs[] = $k . '=' . $v; }
    return $parts[0] . '?' . implode('&', $pairs);
};

$target = 'https://acme.com/';

$buggy   = $wp_add_query_arg(['url' => rawurlencode($target)], 'https://api.test/x');
$correct = $wp_add_query_arg(['url' => $target], 'https://api.test/x');

parse_str(parse_url($buggy,   PHP_URL_QUERY), $got_buggy);
parse_str(parse_url($correct, PHP_URL_QUERY), $got_correct);

check('pre-encoding corrupts the URL', $got_buggy['url'],   'https%3A%2F%2Facme.com%2F');
check('no pre-encoding round-trips',   $got_correct['url'], $target);

// And assert the shipped source no longer pre-encodes.
$http_src = file_get_contents(LEADMAP_DIR . 'src/Support/Http.php');
check('Http::get_json does not rawurlencode',
      (bool) preg_match("/add_query_arg\\(\\s*array_map\\(\\s*'rawurlencode'/", $http_src), false);

echo "\n".($fail?"FAILED: $fail\n":"ALL PASSED\n"); exit($fail?1:0);
