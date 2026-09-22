<?php
/**
 * When a search must stop.
 *
 * Regression guard for a search that ran 34 pages for 7 leads and $1.19. Google kept
 * returning a nextPageToken alongside empty result sets, and the only stop conditions were
 * "no token" and "hit the result cap" — which with no new results is never reached.
 */
declare(strict_types=1);
define('ABSPATH','/tmp/'); define('LEADMAP_DIR', dirname(__DIR__) . '/');
define('LEADMAP_VERSION','0.3.2'); define('SECURE_AUTH_KEY','k'); define('AUTH_SALT','s');
function __($t,$d=''){return $t;}

$fail=0;
function check($l,$a,$e){global $fail; $ok=$a===$e; if(!$ok)$fail++;
  printf("%s %-52s got %-16s want %s\n",$ok?'PASS':'FAIL',$l,var_export($a,true),var_export($e,true));}

// Mirrors the decision in Search_Runner::run(). Kept in step by the source assertions below.
const MAX_EMPTY  = 1;
const MAX_BARREN = 3;
const HARD_CAP   = 10;

function decide(array $o): string {
    $o += ['has_more'=>true,'found'=>0,'max'=>60,'empty_streak'=>0,'barren_streak'=>0,
           'pages'=>1,'page_size'=>20,'cost'=>0.0,'cost_cap'=>0.50];
    $budget = max(1, (int) ceil($o['max'] / max(1, $o['page_size'])));
    return match (true) {
        ! $o['has_more']                       => 'exhausted',
        $o['found'] >= $o['max']               => 'reached_limit',
        $o['empty_streak']  >= MAX_EMPTY       => 'empty_pages',
        $o['barren_streak'] >= MAX_BARREN      => 'no_new_results',
        $o['pages'] >= $budget                 => 'page_budget',
        $o['pages'] >= HARD_CAP                => 'page_cap',
        $o['cost_cap'] > 0 && $o['cost'] >= $o['cost_cap'] => 'cost_cap',
        default                                => '',
    };
}

echo "== the exact failure that happened ==\n";
// Page 2 came back empty while Google still offered another page.
check('empty page stops immediately',
      decide(['found'=>7,'empty_streak'=>1,'pages'=>2]), 'empty_pages');
check('and never reaches page 34',
      decide(['found'=>7,'empty_streak'=>1,'pages'=>34]) !== '', true);

echo "\n== normal completion still works ==\n";
check('no more pages',        decide(['has_more'=>false,'found'=>41,'pages'=>3]), 'exhausted');
check('hit the result cap',   decide(['found'=>60,'pages'=>3]), 'reached_limit');
check('mid-search continues', decide(['found'=>20,'pages'=>1]), '');
check('page 2 of 3 continues',decide(['found'=>40,'pages'=>2]), '');

echo "\n== duplicates-only pages ==\n";
check('1 barren page continues', decide(['found'=>20,'barren_streak'=>1,'pages'=>2]), '');
check('2 barren pages continue', decide(['found'=>20,'barren_streak'=>2,'pages'=>2]), '');
check('3 barren pages stop',     decide(['found'=>20,'barren_streak'=>3,'pages'=>2]), 'no_new_results');

echo "\n== page budget follows the result cap ==\n";
check('60 results = 3 pages', decide(['found'=>21,'max'=>60,'pages'=>3]), 'page_budget');
check('20 results = 1 page',  decide(['found'=>7,'max'=>20,'pages'=>1]), 'page_budget');
check('40 results = 2 pages', decide(['found'=>7,'max'=>40,'pages'=>2]), 'page_budget');
check('under budget runs on', decide(['found'=>7,'max'=>60,'pages'=>2]), '');

echo "\n== spend limit per search ==\n";
check('under the limit',    decide(['found'=>7,'max'=>200,'pages'=>4,'cost'=>0.14]), '');
check('at the limit stops', decide(['found'=>7,'max'=>200,'pages'=>4,'cost'=>0.50]), 'cost_cap');
check('disabled by zero',   decide(['found'=>7,'max'=>200,'pages'=>4,'cost'=>9.99,'cost_cap'=>0]), '');

echo "\n== the runaway is bounded from every direction ==\n";
// With the guards off one at a time, something else must still catch it.
$worst = 0.0;
for ($page = 1; $page <= 50; $page++) {
    $stop = decide(['found'=>7,'max'=>1000,'pages'=>$page,'cost'=>$page*0.035,'empty_streak'=>0,'barren_streak'=>0]);
    if ($stop !== '') { $worst = $page * 0.035; break; }
}
check('a no-results search cannot exceed the cap', $worst <= 0.55, true);
printf("     worst case spend before stopping: $%.2f (was $1.19)\n", $worst);

echo "\n== the source matches these constants ==\n";
$src = file_get_contents(LEADMAP_DIR . 'src/Search/Search_Runner.php');
check('MAX_EMPTY_PAGES',  (bool) preg_match('/MAX_EMPTY_PAGES\s*=\s*1;/', $src), true);
check('MAX_BARREN_PAGES', (bool) preg_match('/MAX_BARREN_PAGES\s*=\s*3;/', $src), true);
check('HARD_PAGE_CAP',    (bool) preg_match('/HARD_PAGE_CAP\s*=\s*10;/', $src), true);
check('page budget from page_size', (bool) preg_match('/ceil\(.*max_results.*page_size\(\)/', $src), true);
check('empty pages checked',  str_contains($src, "=> 'empty_pages'"), true);
check('cost cap checked',     str_contains($src, "=> 'cost_cap'"), true);

echo "\n".($fail?"FAILED: $fail\n":"ALL PASSED\n"); exit($fail?1:0);
