<?php
/**
 * Email discovery and ranking. These cases are drawn from the shapes small business sites
 * actually use — mailto links, obfuscated text, JSON-LD, and footers full of junk.
 */
declare(strict_types=1);
define('ABSPATH','/tmp/'); define('LEADMAP_DIR', dirname(__DIR__) . '/');
define('LEADMAP_VERSION','0.1.0'); define('SECURE_AUTH_KEY','k'); define('AUTH_SALT','s');

function __($t,$d=''){return $t;}
// WordPress's is_email, close enough for the paths under test.
function is_email($e){
  if (strlen($e) < 6 || !str_contains($e,'@')) return false;
  if (substr_count($e,'@') !== 1) return false;
  [$l,$d] = explode('@',$e);
  if ($l === '' || $d === '') return false;
  if (!preg_match('/^[a-zA-Z0-9!#$%&\'*+\/=?^_`{|}~.-]+$/',$l)) return false;
  if (str_starts_with($l,'.')||str_ends_with($l,'.')||str_contains($l,'..')) return false;
  if (!str_contains($d,'.')) return false;
  foreach (explode('.',$d) as $sub) {
    if ($sub==='' || !preg_match('/^[a-z0-9-]+$/i',$sub) || str_starts_with($sub,'-') || str_ends_with($sub,'-')) return false;
  }
  return true;
}

require LEADMAP_DIR.'src/Autoloader.php'; LeadMap\Autoloader::register();
use LeadMap\Enrich\Email_Extractor;

$fail=0;
function check($l,$a,$e){global $fail; $ok=$a===$e; if(!$ok)$fail++;
  printf("%s %-46s got %-30s want %s\n",$ok?'PASS':'FAIL',$l,
    var_export(is_array($a)?implode(',',$a):$a,true), var_export(is_array($e)?implode(',',$e):$e,true));}

$x = new Email_Extractor();
$emails = fn(array $c) => array_keys($c);

echo "== mailto is the strongest signal ==\n";
$r = $x->extract('<a href="mailto:owner@acmedental.com">Email us</a>', 'acmedental.com', 'contact');
check('found',      $emails($r), ['owner@acmedental.com']);
check('source',     $r['owner@acmedental.com']->source, 'mailto');
check('high score', $r['owner@acmedental.com']->confidence >= 95, true);

echo "\n== obfuscation ==\n";
foreach ([
  'owner (at) acmedental (dot) com' => 'owner@acmedental.com',
  'owner [at] acmedental [dot] com' => 'owner@acmedental.com',
  'owner at acmedental dot com'     => 'owner@acmedental.com',
  'owner&#64;acmedental.com'        => 'owner@acmedental.com',
] as $raw => $want) {
  $r = $x->extract("<p>Write to $raw today</p>", 'acmedental.com', 'contact');
  check('decodes: '.substr($raw,0,28), $emails($r), [$want]);
}

echo "\n== JSON-LD ==\n";
$r = $x->extract('<script type="application/ld+json">{"@type":"Dentist","email":"hello@acmedental.com"}</script>', 'acmedental.com', 'home');
check('found in schema', $emails($r), ['hello@acmedental.com']);
check('source',          $r['hello@acmedental.com']->source, 'json_ld');

echo "\n== Cloudflare email obfuscation ==\n";
// Cloudflare strips mailto: out of the HTML and leaves a hex blob its own JavaScript
// decodes. On by default for many plans, so without this a large share of small business
// sites look as though they publish no email at all. Single-byte XOR, key first.
$cf = fn(string $email, int $key = 0x28) => bin2hex(chr($key) .
      implode('', array_map(fn($c) => chr(ord($c) ^ $key), str_split($email))));

$blob = $cf('orchardplumbingandheating@yahoo.com');
check('decoder round-trips', Email_Extractor::decode_cfemail($blob), 'orchardplumbingandheating@yahoo.com');

$r = $x->extract('<a href="/cdn-cgi/l/email-protection#' . $blob . '" class="g11">
  <span class="__cf_email__" data-cfemail="' . $blob . '">[email&#160;protected]</span></a>',
  'orchardplumbingandheating.info', 'contact');
check('extracted from markup', $emails($r), ['orchardplumbingandheating@yahoo.com']);
check('source recorded',       $r['orchardplumbingandheating@yahoo.com']->source, 'cloudflare');
check('scored like a mailto',  $r['orchardplumbingandheating@yahoo.com']->confidence, 85);

check('href form alone', $emails($x->extract(
  '<a href="/cdn-cgi/l/email-protection#' . $cf('info@acme.com', 0x3f) . '">Email</a>',
  'acme.com', 'contact')), ['info@acme.com']);

check('different XOR key', Email_Extractor::decode_cfemail($cf('bob@acme.com', 0x7a)), 'bob@acme.com');
check('key 0x00',          Email_Extractor::decode_cfemail($cf('bob@acme.com', 0x00)), 'bob@acme.com');

echo "\n== malformed Cloudflare blobs are rejected, not guessed at ==\n";
foreach ([
  'too short'      => 'ab',
  'odd length'     => 'abc',
  'not hex'        => 'zzzzzzzz',
  'empty'          => '',
  'decodes to junk'=> bin2hex("\x05\x01\x02\x03\x04"),
] as $label => $bad) {
  check('rejects ' . $label, Email_Extractor::decode_cfemail($bad), '');
}
check('junk blob yields no email', $emails($x->extract(
  '<span data-cfemail="deadbeefdeadbeef">x</span>', 'acme.com', 'home')), []);

echo "\n== junk must never become a lead ==\n";
foreach ([
  'image filename'   => '<img src="logo@2x.png">',
  'placeholder'      => '<input placeholder="youremail@example.com">',
  'noreply'          => '<p>noreply@acmedental.com</p>',
  'sentry dsn'       => '<script>https://abc@sentry.io/123</script>',
  'wix tooling'      => '<p>support@wixpress.com</p>',
  'cache hash'       => '<p>a3f9c2e8b1d40f77@acmedental.com</p>',
  'script contents'  => '<script>var e="tracker@analytics-vendor.com";</script>',
] as $label => $html) {
  check('rejects '.$label, $emails($x->extract($html, 'acmedental.com', 'home')), []);
}

echo "\n== ranking: own domain beats free mailbox beats third party ==\n";
$html = '<a href="mailto:info@acmedental.com">info</a>
         <p>acmedental@gmail.com</p>
         <footer>Site by hello@webagency.co</footer>';
$r = $x->merge([$x->extract($html, 'acmedental.com', 'contact')]);
check('three found',   count($r), 3);
check('own domain 1st',$r[0]->email, 'info@acmedental.com');
check('agency last',   $r[2]->email, 'hello@webagency.co');
check('role flagged',  $r[0]->is_role_account, true);

echo "\n== personal address outranks role account on same domain ==\n";
$r = $x->merge([$x->extract(
  '<a href="mailto:info@acmedental.com">a</a><a href="mailto:dr.smith@acmedental.com">b</a>',
  'acmedental.com', 'contact')]);
check('personal first', $r[0]->email, 'dr.smith@acmedental.com');
check('role second',    $r[1]->email, 'info@acmedental.com');

echo "\n== merging across pages keeps best evidence ==\n";
$home    = $x->extract('<p>owner@acmedental.com</p>', 'acmedental.com', 'home');
$contact = $x->extract('<a href="mailto:owner@acmedental.com">x</a>', 'acmedental.com', 'contact');
$merged  = $x->merge([$home, $contact]);
check('deduplicated', count($merged), 1);
check('kept mailto',  $merged[0]->source, 'mailto');

echo "\n== no email found ==\n";
check('empty page', $emails($x->extract('<html><body>Call us on 212-555-0147</body></html>','acmedental.com','home')), []);

echo "\n".($fail?"FAILED: $fail\n":"ALL PASSED\n"); exit($fail?1:0);
