<?php
declare(strict_types=1);
define('ABSPATH','/tmp/'); define('LEADMAP_DIR', dirname(__DIR__) . '/');
define('LEADMAP_VERSION','0.1.0'); define('SECURE_AUTH_KEY','k'); define('AUTH_SALT','s');
class WP_Error {
  public function __construct(private $c='', private $m='', private $d=null){}
  public function get_error_code(){return $this->c;}
  public function get_error_message(){return $this->m;}
  public function get_error_data(){return $this->d;}
}
function wp_parse_url($u,$c=-1){return parse_url($u,$c);}
function __($t,$d=''){return $t;}
function apply_filters($h,$v,...$a){return $v;}
function do_action($h,...$a){}
function get_option($k,$d=false){return $d;}
require LEADMAP_DIR.'src/Autoloader.php'; LeadMap\Autoloader::register();

$p = new LeadMap\Providers\Google_Places_Provider();
$m = new ReflectionMethod($p,'explain'); $m->setAccessible(true);

$cases = [
  'API keys with referer restrictions cannot be used with this API.',
  'The provided API key has IP address restrictions and this IP is not allowed.',
  'The provided API key is expired. API key not valid.',
  'Places API (New) has not been used in project 12345 before or it is disabled.',
  'This API project is not authorized to use this API.',
  'You must enable billing on the Google Cloud project.',
  'Requests to this API places.googleapis.com method google.maps.places.v1.Places.SearchText are blocked.',
];
$fail = 0;

foreach ($cases as $raw) {
  $out = $m->invoke($p, new WP_Error('leadmap_geocode_failed', $raw));
  $mapped = $out->get_error_message() !== $raw;
  if (!$mapped) { $fail++; }
  printf("%s  %s\n     -> %s\n\n", $mapped ? 'PASS' : 'FAIL',
    substr($raw, 0, 58), substr($out->get_error_message(), 0, 110) . '...');
}

// An error we have no hint for must pass through untouched rather than be swallowed.
$unknown = 'Some entirely new Google error.';
$out = $m->invoke($p, new WP_Error('x', $unknown));
$ok = $out->get_error_message() === $unknown;
if (!$ok) { $fail++; }
printf("%s  unrecognised error passes through unchanged\n", $ok ? 'PASS' : 'FAIL');

echo "\n" . ($fail ? "FAILED: $fail\n" : "ALL PASSED\n");
exit($fail ? 1 : 0);
