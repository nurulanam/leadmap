<?php
/**
 * Contact-page detection across the URL shapes real small-business sites actually use:
 * classic ASP, PHP, .html, mixed separators, image-only navigation, and other languages.
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
function wp_strip_all_tags($s,$b=false){return trim(strip_tags($s));}
function __($t,$d=''){return $t;}

require LEADMAP_DIR.'src/Autoloader.php'; LeadMap\Autoloader::register();
use LeadMap\Enrich\Website_Crawler;

$fail=0;
$crawler = new Website_Crawler();
$classify = new ReflectionMethod($crawler, 'classify');
$classify->setAccessible(true);

function kind(string $href, string $inner = 'Click here', string $attrs = ''): string {
    global $crawler, $classify;
    return $classify->invoke($crawler, $href, $inner, $attrs);
}
function check($l,$a,$e){global $fail; $ok=$a===$e; if(!$ok)$fail++;
  printf("%s %-46s got %-10s want %s\n",$ok?'PASS':'FAIL',$l,var_export($a,true),var_export($e,true));}

echo "== file extensions and platforms ==\n";
foreach ([
  '/contact.html', '/contact.htm', '/contact.php', '/contact.aspx', '/contact.asp',
  '/contact.jsp', '/contact.cfm', '/contactus.html', '/contact_us.php',
  '/Contact-Us.aspx', '/CONTACT.HTM', '/pages/contact-us.html',
  '/index.php?page=contact', '/contact/', '/contact/index.html',
] as $path) {
  check($path, kind($path), 'contact');
}

echo "\n== odd separators and typos ==\n";
foreach ([
  '/contac-tus.html'  => 'contact',   // hyphen in the wrong place
  '/contact--us.html' => 'contact',
  '/contact_us_now'   => 'contact',
  '/contact%20us.html'=> 'contact',
  '/Contact.Us.html'  => 'contact',
  '/c/contact-us'     => 'contact',
] as $path => $want) { check($path, kind($path), $want); }

echo "\n== other languages ==\n";
foreach ([
  '/kontakt.html'      => 'contact',
  '/contacto.php'      => 'contact',
  '/contactenos'       => 'contact',
  '/contatti.html'     => 'contact',
  '/contato'           => 'contact',
  '/nous-contacter'    => 'contact',
  '/impressum.html'    => 'contact',
  '/coordonn%C3%A9es'  => 'contact',
  '/ueber-uns.html'    => 'about',
  '/chi-siamo'         => 'about',
] as $path => $want) { check($path, kind($path), $want); }

echo "\n== link text carries it when the URL does not ==\n";
check('opaque url, clear text',  kind('/page/id/47', 'Contact Us'), 'contact');
check('query-string page',       kind('/index.asp?p=7', 'Get In Touch'), 'contact');
check('image link with alt',     kind('/p/9', '<img src="/btn.gif" alt="Contact Us">'), 'contact');
check('title attribute',         kind('/p/9', '<img src="/b.gif">', 'title="Contact us today"'), 'contact');
check('aria-label',              kind('/p/9', '', 'aria-label="Contact"'), 'contact');
check('quote request',           kind('/rfq.html', 'Request a Quote'), 'contact');

echo "\n== about and support are ranked lower, not ignored ==\n";
check('about us',    kind('/about-us.html'), 'about');
check('our team',    kind('/our-team.php'), 'about');
check('meet team',   kind('/x', 'Meet The Team'), 'about');
check('support',     kind('/support.html'), 'other');
check('locations',   kind('/our-locations'), 'other');

echo "\n== must not match ==\n";
foreach ([
  '/products.html', '/services/plumbing', '/blog/2019/06/summer-tips',
  '/gallery.php', '/privacy-policy', '/index.html', '/cart', '/login.aspx',
] as $path) { check($path, kind($path), ''); }

echo "\n== false friends ==\n";
check('contact lenses (optician)', kind('/contact-lenses.html', 'Contact Lenses'), '');
check('contactless payment',       kind('/contactless-payments'), '');
check('contact form 7 asset',      kind('/wp-content/plugins/contact-form-7/x.js'), '');

echo "\n== end to end through contact_links ==\n";
$html = '<nav>
  <a href="/index.html">Home</a>
  <a href="/services.html">Services</a>
  <a href="/about-us.html">About Us</a>
  <a href="/contac-tus.html"><img src="/img/contact.gif" alt="Contact Us"></a>
  <a href="https://facebook.com/acme">Facebook</a>
  <a href="#top">Top</a>
  <a href="mailto:x@acme.com">Email</a>
</nav>';
$links = $crawler->contact_links($html, 'https://acme.com/index.html');
$urls  = array_keys($links);

check('found two pages',        count($links), 2);
check('contact ranked first',   $urls[0], 'https://acme.com/contac-tus.html');
check('about ranked second',    $urls[1], 'https://acme.com/about-us.html');
check('off-site link skipped',  in_array('https://facebook.com/acme', $urls, true), false);
check('anchor skipped',         in_array('https://acme.com/#top', $urls, true), false);

echo "\n== relative paths resolve against the current directory ==\n";
$rel = $crawler->contact_links('<a href="contact.html">Contact</a>', 'https://acme.com/pages/index.html');
check('relative resolved', array_key_first($rel), 'https://acme.com/pages/contact.html');
$root = $crawler->contact_links('<a href="/contact.html">Contact</a>', 'https://acme.com/pages/index.html');
check('root-relative resolved', array_key_first($root), 'https://acme.com/contact.html');

echo "\n".($fail?"FAILED: $fail\n":"ALL PASSED\n"); exit($fail?1:0);
