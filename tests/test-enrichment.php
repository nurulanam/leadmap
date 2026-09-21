<?php
/**
 * The analysis pipeline: tech fingerprinting, on-page SEO, and the staleness roll-up.
 * Two fixtures — a site frozen in 2011, and a current one — must land far apart.
 */
declare(strict_types=1);
define('ABSPATH','/tmp/'); define('LEADMAP_DIR', dirname(__DIR__) . '/');
define('LEADMAP_VERSION','0.1.0'); define('SECURE_AUTH_KEY','k'); define('AUTH_SALT','s');

function __($t,$d=''){return $t;}
function wp_strip_all_tags($s,$b=false){return trim(strip_tags($s));}
function wp_parse_url($u,$c=-1){return $c===-1?parse_url($u):parse_url($u,$c);}
function current_time($t,$g=0){return gmdate('Y-m-d H:i:s');}

require LEADMAP_DIR.'src/Autoloader.php'; LeadMap\Autoloader::register();

use LeadMap\Enrich\{Fetch_Result, Tech_Detector, Seo_Analyzer, Staleness_Scorer, Robots};

$fail=0;
function check($l,$a,$e){global $fail; $ok=$a===$e; if(!$ok)$fail++;
  printf("%s %-48s got %-22s want %s\n",$ok?'PASS':'FAIL',$l,
    var_export(is_array($a)?implode(',',$a):$a,true), var_export(is_array($e)?implode(',',$e):$e,true));}
function gt($l,$a,$min){global $fail; $ok=$a>$min; if(!$ok)$fail++;
  printf("%s %-48s got %-22s want > %s\n",$ok?'PASS':'FAIL',$l,var_export($a,true),var_export($min,true));}
function lt($l,$a,$max){global $fail; $ok=$a<$max; if(!$ok)$fail++;
  printf("%s %-48s got %-22s want < %s\n",$ok?'PASS':'FAIL',$l,var_export($a,true),var_export($max,true));}

$page = fn(string $html, string $url='https://acme.com/') => new Fetch_Result(
  url: $url, final_url: $url, status: 200, body: $html,
  headers: ['content-type'=>'text/html; charset=utf-8','server'=>'Apache/2.2.15']);

// ---- Fixture A: a site last touched in 2011 -------------------------------------
$old = $page('<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN">
<html><head>
<meta name="generator" content="WordPress 4.9.8">
<title>Acme</title>
<script src="/js/jquery-1.7.2.min.js"></script>
</head><body>
<table cellpadding="4" border="1"><tr><td><font size="2">Welcome to Acme Plumbing</font></td></tr></table>
<object type="application/x-shockwave-flash" data="/intro.swf"></object>
<img src="/logo.gif">
<img src="/van.jpg">
<p>Call 212-555-0147</p>
<div id="footer">&copy; 2011 Acme Plumbing</div>
</body></html>', 'http://acme.com/');

// ---- Fixture B: a current site ---------------------------------------------------
$new = $page('<!DOCTYPE html><html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Acme Plumbing — Emergency Plumbers in Manhattan</title>
<meta name="description" content="Licensed emergency plumbers serving Manhattan since 1998. Same-day callouts.">
<link rel="canonical" href="https://acme.com/">
<meta property="og:title" content="Acme Plumbing">
<link rel="icon" href="/favicon.ico">
<script type="application/ld+json">{"@type":"Plumber","email":"office@acme.com"}</script>
</head><body>
<h1>Emergency Plumbers in Manhattan</h1>
<img src="/team.webp" alt="Our team">
<footer>&copy; ' . gmdate('Y') . ' Acme Plumbing</footer>
</body></html>');

$tech = new Tech_Detector(); $seo = new Seo_Analyzer(); $scorer = new Staleness_Scorer();

echo "== fixture A: site frozen in 2011 ==\n";
$t = $tech->detect($old);
check('detects WordPress',     $t['cms'], 'WordPress');
check('detects version',       $t['cms_version'], '4.9.8');
check('detects jQuery',        $t['jquery_version'], '1.7.2');
check('flags flash',           in_array('flash',$t['dated_markers'],true), true);
check('flags font tag',        in_array('font_tag',$t['dated_markers'],true), true);
check('flags table layout',    in_array('table_layout',$t['dated_markers'],true), true);
check('flags old jquery',      in_array('jquery_1.7.2',$t['dated_markers'],true), true);
check('flags old wordpress',   in_array('wordpress_4.9.8',$t['dated_markers'],true), true);

$s = $seo->analyze($old);
check('no https',              $s['is_https'], false);
check('no viewport',           $s['has_viewport'], false);
check('no description',        $s['has_description'], false);
check('no h1',                 $s['h1_count'], 0);
check('copyright year',        $s['copyright_year'], 2011);
check('alt coverage 0%',       $s['alt_coverage'], 0);
gt('seo gap score',            $s['gap_score'], 60);

$scored = $scorer->score(['http'=>['status'=>200],'seo'=>$s,'tech'=>$t,
                          'speed_mobile'=>['score'=>21]]);
check('staleness maxes out',   $scored['score'], 100);
gt('many signals',             count($scored['signals']), 8);

echo "\n== fixture B: current site ==\n";
$t2 = $tech->detect($new); $s2 = $seo->analyze($new);
check('no dated markers',      $t2['dated_markers'], []);
check('https',                 $s2['is_https'], true);
check('viewport',              $s2['has_viewport'], true);
check('description',           $s2['has_description'], true);
check('single h1',             $s2['has_single_h1'], true);
check('schema',                $s2['has_schema'], true);
check('canonical',             $s2['has_canonical'], true);
check('alt coverage 100%',     $s2['alt_coverage'], 100);
check('current copyright',     $s2['copyright_year'], (int) gmdate('Y'));
lt('low seo gap',              $s2['gap_score'], 10);

$scored2 = $scorer->score(['http'=>['status'=>200],'seo'=>$s2,'tech'=>$t2,
                           'speed_mobile'=>['score'=>96]]);
check('staleness zero',        $scored2['score'], 0);
check('no signals',            $scored2['signals'], []);

echo "\n== broken sites ==\n";
check('dead host scores high', $scorer->score(['unreachable'=>true])['score'] >= 45, true);
check('500 error',             $scorer->score(['http'=>['status'=>503]])['score'] >= 40, true);
check('404 home page',         $scorer->score(['http'=>['status'=>404]])['score'] >= 40, true);

echo "\n== copyright year edge cases ==\n";
$cy = fn(string $h) => $seo->analyze($page($h))['copyright_year'];
check('range form',      $cy('<footer>&copy; 2009-2014 Acme</footer>'), 2014);
check('word form',       $cy('<footer>Copyright 2016 Acme</footer>'), 2016);
check('none',            $cy('<footer>Acme Plumbing</footer>'), 0);
check('future ignored',  $cy('<footer>&copy; 2099 Acme</footer>'), 0);
check('picks latest',    $cy('<p>&copy; 2010</p><footer>&copy; 2018</footer>'), 2018);

echo "\n== robots.txt ==\n";
$r = new Robots("User-agent: *\nDisallow: /wp-admin/\nDisallow: /private\n");
check('blocks disallowed',  $r->allows('/wp-admin/options.php'), false);
check('blocks prefix',      $r->allows('/private/page'), false);
check('allows contact',     $r->allows('/contact'), true);
check('empty robots allows',(new Robots(''))->allows('/anything'), true);
$all = new Robots("User-agent: *\nDisallow: /\n");
check('full block',         $all->allows('/contact'), false);
$named = new Robots("User-agent: *\nDisallow: /\n\nUser-agent: LeadMapBot\nDisallow: /secret\n");
check('named group wins',   $named->allows('/contact'), true);
check('named group applies',$named->allows('/secret/x'), false);
$empty = new Robots("User-agent: *\nDisallow:\n");
check('empty disallow = allow all', $empty->allows('/anything'), true);

echo "\n".($fail?"FAILED: $fail\n":"ALL PASSED\n"); exit($fail?1:0);
