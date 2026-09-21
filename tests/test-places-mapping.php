<?php
declare(strict_types=1);
define('ABSPATH','/tmp/'); define('LEADMAP_DIR', dirname(__DIR__) . '/');
define('LEADMAP_VERSION','0.1.0'); define('SECURE_AUTH_KEY','k'); define('AUTH_SALT','s');
function wp_parse_url($u,$c=-1){return parse_url($u,$c);}
function wp_json_encode($d){return json_encode($d);}
function __($t,$d=''){return $t;}
function apply_filters($h,$v,...$a){return $v;}
function do_action($h,...$a){}
function get_option($k,$d=false){return $d;}
function wp_parse_args($a,$d){return array_merge($d,$a);}
function home_url($p='/'){return 'https://example.test'.$p;}
require LEADMAP_DIR.'src/Autoloader.php'; LeadMap\Autoloader::register();

$fail=0;
function check($l,$a,$e){global $fail; $ok=$a===$e; if(!$ok)$fail++;
  printf("%s %-26s got %-24s want %s\n",$ok?'PASS':'FAIL',$l,var_export($a,true),var_export($e,true));}

// A realistic Places API (New) searchText item.
$raw = [
  'id' => 'ChIJN1t_tDeuEmsRUsoyG83frY4',
  'displayName' => ['text' => 'Midtown Family Dental', 'languageCode' => 'en'],
  'formattedAddress' => '245 W 34th St, New York, NY 10001, USA',
  'addressComponents' => [
    ['longText'=>'245','shortText'=>'245','types'=>['street_number']],
    ['longText'=>'West 34th Street','shortText'=>'W 34th St','types'=>['route']],
    ['longText'=>'Manhattan','shortText'=>'Manhattan','types'=>['sublocality','political']],
    ['longText'=>'New York','shortText'=>'New York','types'=>['locality','political']],
    ['longText'=>'New York','shortText'=>'NY','types'=>['administrative_area_level_1','political']],
    ['longText'=>'United States','shortText'=>'US','types'=>['country','political']],
    ['longText'=>'10001','shortText'=>'10001','types'=>['postal_code']],
  ],
  'nationalPhoneNumber' => '(212) 555-0147',
  'internationalPhoneNumber' => '+1 212-555-0147',
  'websiteUri' => 'https://www.midtownfamilydental.com/',
  'rating' => 4.6,
  'userRatingCount' => 218,
  'primaryType' => 'dental_clinic',
  'location' => ['latitude'=>40.7509,'longitude'=>-73.9911],
  'googleMapsUri' => 'https://maps.google.com/?cid=123',
];

$provider = new LeadMap\Providers\Google_Places_Provider();
$m = new ReflectionMethod($provider,'map_place'); $m->setAccessible(true);
$p = $m->invoke($provider, $raw);

echo "== map_place ==\n";
check('external_id',  $p->external_id, 'ChIJN1t_tDeuEmsRUsoyG83frY4');
check('name',         $p->name, 'Midtown Family Dental');
check('phone is intl',$p->phone, '+1 212-555-0147');
check('website',      $p->website, 'https://www.midtownfamilydental.com/');
check('city',         $p->city, 'New York');
check('state short',  $p->state, 'NY');
check('country short',$p->country, 'US');
check('zip',          $p->zip, '10001');
check('category',     $p->category, 'dental_clinic');
check('rating',       $p->rating, 4.6);
check('reviews',      $p->review_count, 218);
check('lat',          $p->lat, 40.7509);

echo "\n== sparse record (no site, no phone, no rating) ==\n";
$sparse = $m->invoke($provider, ['id'=>'X1','displayName'=>['text'=>'Corner Barber'],'formattedAddress'=>'12 Main St']);
check('name',    $sparse->name, 'Corner Barber');
check('website', $sparse->website, '');
check('phone',   $sparse->phone, '');
check('rating',  $sparse->rating, null);
check('lat',     $sparse->lat, null);
check('reviews', $sparse->review_count, 0);

echo "\n== derived values ==\n";
check('domain',    LeadMap\Support\Normalize::domain($p->website), 'midtownfamilydental.com');
check('e164',      LeadMap\Support\Normalize::phone_e164($p->phone,'US'), '+12125550147');
check('category',  LeadMap\Support\Normalize::humanize_type($p->category), 'Dental Clinic');

echo "\n== cost estimate ==\n";
check('60 results = 3 pages', $provider->cost_estimate(60), 0.105);
check('20 results = 1 page',  $provider->cost_estimate(20), 0.035);

echo "\n".($fail?"FAILED: $fail\n":"ALL PASSED\n"); exit($fail?1:0);
