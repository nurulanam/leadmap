<?php
/**
 * Every class referenced anywhere in the plugin must actually resolve.
 *
 * A missing `use` statement is invisible to `php -l`: the file parses, and PHP only fatals
 * when that line is reached at runtime. A REST route shipped with `Rate_Limiter` unimported
 * and fatalled on every call, which looked like the whole plugin hanging.
 */
declare(strict_types=1);

define('LEADMAP_DIR', dirname(__DIR__) . '/');
define('ABSPATH', sys_get_temp_dir() . '/leadmap-refcheck/');
define('LEADMAP_VERSION', '0.0.0');

// Stub the one core class the plugin extends, so its file can be loaded here.
@mkdir(ABSPATH . 'wp-admin/includes', 0777, true);
file_put_contents(ABSPATH . 'wp-admin/includes/class-wp-list-table.php', '<?php class WP_List_Table { public $items = []; protected $_column_headers = []; public function __construct($a = []) {} }');
require LEADMAP_DIR . 'src/Autoloader.php';
LeadMap\Autoloader::register();

$builtin = ['WP_Error','WP_REST_Request','WP_REST_Response','WP_List_Table','Generator',
            'ReflectionMethod','ReflectionClass','DateTime','Exception','Error','ArrayObject'];
$bad = 0;

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(LEADMAP_DIR . 'src'));

foreach ($rii as $f) {
    if ($f->getExtension() !== 'php') { continue; }

    $src = file_get_contents($f->getPathname());
    $rel = str_replace(LEADMAP_DIR, '', $f->getPathname());

    preg_match('/^namespace\s+([^;]+);/m', $src, $ns);
    $namespace = trim($ns[1] ?? '');

    preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+);/m', $src, $u);
    $imports = [];
    foreach ($u[1] as $imp) {
        $short = substr($imp, strrpos($imp, '\\') + 1);
        $imports[$short] = $imp;
    }

    // Fully-qualified inline references, e.g. \LeadMap\Triage\Screenshot_Store::url()
    preg_match_all('/\\\\(LeadMap\\\\[A-Za-z0-9_\\\\]+)::/', $src, $fq);
    foreach (array_unique($fq[1]) as $fqcn) {
        if (!class_exists($fqcn) && !interface_exists($fqcn)) {
            printf("UNRESOLVED (fq)  %-46s %s\n", $fqcn, $rel);
            $bad++;
        }
    }

    // Short references.
    preg_match_all('/(?<![\\\\$>a-zA-Z0-9_])([A-Z][A-Za-z0-9_]*)::/', $src, $m1);
    preg_match_all('/new\s+([A-Z][A-Za-z0-9_]*)\s*\(/', $src, $m2);

    foreach (array_unique(array_merge($m1[1], $m2[1])) as $ref) {
        if (in_array($ref, $builtin, true)) { continue; }

        if (isset($imports[$ref])) {
            $fqcn = $imports[$ref];
        } elseif ($namespace !== '') {
            $fqcn = $namespace . '\\' . $ref;
        } else {
            $fqcn = $ref;
        }

        if (!class_exists($fqcn) && !interface_exists($fqcn)) {
            printf("UNRESOLVED       %-46s %s\n", $ref, $rel);
            $bad++;
        }
    }
}

if (0 === $bad) {
    echo "PASS  every class reference resolves\n\nALL PASSED\n";
    exit(0);
}

echo "\nFAILED: $bad unresolved reference(s) — each one is a fatal waiting to happen\n";
exit(1);
