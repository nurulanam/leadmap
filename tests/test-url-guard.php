<?php
/**
 * SSRF guard (CWE-918). These assertions are the security contract of the crawler:
 * if any of them start failing, the crawler can be pointed at internal infrastructure.
 */
declare(strict_types=1);
define('ABSPATH','/tmp/'); define('LEADMAP_DIR', dirname(__DIR__) . '/');
define('LEADMAP_VERSION','0.1.0'); define('SECURE_AUTH_KEY','k'); define('AUTH_SALT','s');

class WP_Error {
  public function __construct(private $c='', private $m='', private $d=null){}
  public function get_error_code(){return $this->c;}
  public function get_error_message(){return $this->m;}
  public function get_error_data(){return $this->d;}
}
function is_wp_error($t){return $t instanceof WP_Error;}
function wp_parse_url($u,$c=-1){return $c===-1?parse_url($u):parse_url($u,$c);}
function __($t,$d=''){return $t;}

require LEADMAP_DIR.'src/Autoloader.php'; LeadMap\Autoloader::register();
use LeadMap\Support\Url_Guard;

$fail=0;
function blocked(string $label, string $url): void {
  global $fail;
  $r = Url_Guard::validate($url);
  $ok = is_wp_error($r);
  if(!$ok) $fail++;
  printf("%s  BLOCK %-44s %s\n", $ok?'PASS':'FAIL', substr($url,0,44),
    $ok ? '('.$r->get_error_code().')' : '*** WAS ALLOWED ***');
}
function ipcheck(string $ip, bool $expect_public): void {
  global $fail;
  $got = Url_Guard::is_public_ip($ip);
  $ok = $got === $expect_public;
  if(!$ok) $fail++;
  printf("%s  %-46s public=%s want=%s\n", $ok?'PASS':'FAIL', $ip,
    var_export($got,true), var_export($expect_public,true));
}

echo "== cloud metadata endpoints (highest-value SSRF target) ==\n";
ipcheck('169.254.169.254', false);   // AWS / GCP / Azure IMDS
ipcheck('169.254.170.2',   false);   // AWS ECS task metadata
ipcheck('100.100.100.200', false);   // Alibaba Cloud metadata

echo "\n== loopback and private ranges ==\n";
foreach ([['127.0.0.1',false],['127.1.1.1',false],['0.0.0.0',false],
          ['10.0.0.5',false],['172.16.0.1',false],['172.31.255.255',false],
          ['192.168.1.1',false],['100.64.0.1',false],['192.0.0.1',false],
          ['255.255.255.255',false],['224.0.0.1',false],['198.18.0.1',false]] as [$ip,$pub]) ipcheck($ip,$pub);

echo "\n== IPv6 ==\n";
foreach ([['::1',false],['::',false],['fe80::1',false],['fc00::1',false],['fd00::1',false],
          ['ff02::1',false],['::ffff:127.0.0.1',false],['::ffff:10.0.0.1',false],
          ['2606:4700:4700::1111',true]] as [$ip,$pub]) ipcheck($ip,$pub);

echo "\n== public addresses must still pass ==\n";
foreach ([['8.8.8.8',true],['1.1.1.1',true],['93.184.216.34',true],['172.32.0.1',true]] as [$ip,$pub]) ipcheck($ip,$pub);

echo "\n== URL-level rejections ==\n";
blocked('non-http scheme',      'file:///etc/passwd');
blocked('gopher',               'gopher://evil.com/');
blocked('ftp',                  'ftp://example.com/');
blocked('javascript',           'javascript:alert(1)');
blocked('data uri',             'data:text/html,<script>');
blocked('localhost',            'http://localhost/admin');
blocked('loopback ip',          'http://127.0.0.1:80/');
blocked('metadata by ip',       'http://169.254.169.254/latest/meta-data/');
blocked('metadata by name',     'http://metadata.google.internal/');
blocked('private ip literal',   'http://10.0.0.1/');
blocked('.local suffix',        'http://printer.local/');
blocked('.internal suffix',     'http://db.internal/');
blocked('non-standard port',    'http://example.com:8080/');
blocked('ssh port',             'http://example.com:22/');
blocked('redis port',           'http://example.com:6379/');
blocked('credentials in url',   'http://user:pass@example.com/');
blocked('cred confusion',       'https://example.com@127.0.0.1/');
blocked('CR injection',         "http://example.com/\r\nHost: evil");
blocked('trailing null byte',   "http://example.com/\0");
blocked('embedded null byte',   "http://exa\0mple.com/");
blocked('tab injection',        "http://example.com/\tfoo");
blocked('leading whitespace ok is trimmed then blocked', "  http://127.0.0.1/  ");
blocked('empty',                '');
blocked('no host',              'http://');
blocked('bare hostname',        'http://intranet/');
blocked('ipv6 loopback',        'http://[::1]/');
blocked('overlong', 'http://example.com/' . str_repeat('a', 2100));

echo "\n== a real public site must pass (needs DNS) ==\n";
$r = Url_Guard::validate('https://example.com/');
if (is_wp_error($r) && $r->get_error_code() === 'leadmap_url_dns') {
    echo "SKIP  no DNS available in this sandbox\n";
} else {
    $ok = !is_wp_error($r); if(!$ok) $fail++;
    printf("%s  ALLOW https://example.com/ %s\n", $ok?'PASS':'FAIL',
      $ok ? '' : '('.$r->get_error_message().')');
}

echo "\n".($fail?"FAILED: $fail\n":"ALL PASSED\n"); exit($fail?1:0);
