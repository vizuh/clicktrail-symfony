<?php

declare(strict_types=1);

/**
 * Standalone assert runner - no kernel boot, no kernel dependencies required
 * at runtime. Run directly:
 *   php tests/_runner.php
 * or inside a container:
 *   podman run --rm -v "$PWD":/app:Z wordpress:php8.3-apache php /app/tests/_runner.php
 *
 * Sections that need optional packages (symfony/config) self-skip when the
 * package is absent instead of failing.
 */

use ClickTrail\Symfony\Support\WebhookSignature;

$root = dirname(__DIR__);

// Optional vendor autoloader (composer install) enables the config-tree section.
$hasVendor = false;
foreach ([$root . '/vendor/autoload.php', __DIR__ . '/../../../../../../autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        $hasVendor = true;
        break;
    }
}

// Bundle namespace -> src/
spl_autoload_register(function ($class) use ($root): void {
    $prefix = 'ClickTrail\\Symfony\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

// php-sdk namespace -> sibling path repo (plain PHP, no deps needed here).
spl_autoload_register(function ($class): void {
    $prefix = 'ClickTrail\\';
    if (!str_starts_with($class, $prefix) || str_starts_with($class, 'ClickTrail\\Symfony\\')) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    // ClickTrail\Core\Foo -> src/Core/Foo.php ; ClickTrail\Client\Event\Bar -> src/Client/Event/Bar.php
    foreach (['Core', 'Client', 'Consent', 'Conventions'] as $top) {
        if (str_starts_with($rel, $top . '/')) {
            $sdkRoot = getenv('CLICKTRAIL_SDK_ROOT') ?: dirname(__DIR__, 2) . '/clicktrail-php';
            $path = $sdkRoot . '/src/' . $rel . '.php';
            if (is_file($path)) {
                require $path;
            }

            return;
        }
    }
});

if (!class_exists(\Twig\Extension\AbstractExtension::class)) {
    require __DIR__ . '/_stubs.php';
}

function check(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
}

$failures = 0;

// --- T1: webhook signature true/false ----------------------------------------
$payload = '{"order":"a1","visitor_id":"v-9"}';
$sig = WebhookSignature::sign($payload, 'secret-123');
check(is_string($sig) && str_starts_with($sig, 'sha256=') && strlen($sig) === 71, 'T1 sign returns prefixed HMAC-SHA256');
check(WebhookSignature::verify($payload, $sig, 'secret-123') === true, 'T1 prefixed signature verifies');
check(WebhookSignature::verify($payload, substr($sig, 7), 'secret-123') === true, 'T1 bare hex form accepted');
check(WebhookSignature::verify($payload, $sig, 'wrong-secret') === false, 'T1 wrong secret rejected');
check(WebhookSignature::verify($payload . ' ', $sig, 'secret-123') === false, 'T1 tampered payload rejected');
check(WebhookSignature::verify($payload, '', 'secret-123') === false, 'T1 empty header rejected');
echo "T1 ok: webhook signature\n";

// --- T2: Twig helpers (render-only contract) ---------------------------------
$twigExt = new \ClickTrail\Symfony\Twig\ClickTrailExtension();
$fnName = static fn ($f): string => $f instanceof \Twig\TwigFunction && method_exists($f, 'getName') && !property_exists($f, 'name')
    ? $f->getName()
    : (is_object($f) ? ($f->name ?? $f->getName()) : '');
$names = array_map($fnName, $twigExt->getFunctions());
check(in_array('clicktrail_head', $names, true), 'T2 head fn registered');
check(in_array('clicktrail_hidden_attribution_inputs', $names, true), 'T2 inputs fn registered');

$evil = '"><script>alert(1)</script>';
$head = $twigExt->renderHead([
    'script_src' => '/ct/loader.js?x=1&y=2',
    'site_id' => 'site-001',
    'first_party_endpoint' => 'https://cdn.evil.example/x"onerror="p' . $evil,
]);
check(str_starts_with($head, '<script src="/ct/loader.js?x=1&amp;y=2"'), 'T2 src escaped');
check(strpos($head, 'data-ct-site-id="site-001"') !== false, 'T2 site id attr');
check(strpos($head, '&quot;&gt;&lt;script&gt;') !== false, 'T2 evil endpoint escaped');
check(strpos($head, '<script>alert(1)') === false, 'T2 no raw injection in head');
check(str_ends_with($head, ' async></script>'), 'T2 closing tag');
check($twigExt->renderHead(['script_src' => '']) === '', 'T2 no script_src renders nothing');

$inputs = $twigExt->hiddenAttributionInputs([
    'visitor_id' => 'v-abc',
    'utm_source' => 'google',
    'gclid' => 'XYZ1',
    'utm_term' => null,
    'utm_content' => '',
    'unknown_field' => 'dropped',
]);
$names_rendered = [];
preg_match_all('/name="([^"]+)"/', $inputs, $m);
$names_rendered = $m[1];
check($names_rendered === ['visitor_id', 'utm_source', 'gclid'], 'T2 field order + skip empties/unknowns: ' . json_encode($names_rendered));
check(substr_count($inputs, '<input type="hidden" name="') === 3, 'T2 input count');
$injected = $twigExt->hiddenAttributionInputs(['utm_source' => 'x" onmouseover="alert(1)']);
check(strpos($injected, '" onmouseover') === false, 'T2 attr values escaped');

// --- T3: AttributionContext flat render array (SDK core round-trip) ----------
$core = new \ClickTrail\Core\StoredState(
    first: new \ClickTrail\Core\Touch(source: 'google', medium: 'cpc', landingPage: 'https://ex.com/?utm_source=google'),
    last: new \ClickTrail\Core\Touch(source: 'newsletter', medium: 'email'),
);
$ctx = new \ClickTrail\Symfony\Attribution\AttributionContext(attribution: $core, consent: null);
$flat = $ctx->toRenderArray();
check(($flat['first_source'] ?? null) === 'google', 'T3 first_source flat key');
check(($flat['last_medium'] ?? null) === 'email', 'T3 last_medium flat key');
check(($flat['consent_state'] ?? null) === 'unknown', 'T3 unknown consent default');

// --- T4: config tree processing sample (needs symfony/config via vendor) -----
if ($hasVendor && class_exists(\Symfony\Component\Config\Definition\Processor::class)) {
    $configuration = new \ClickTrail\Symfony\DependencyInjection\Configuration();
    $processor = new \Symfony\Component\Config\Definition\Processor();

    $resolved = $processor->processConfiguration($configuration, [[]]);
    check($resolved['consent_required'] === true, 'T4 consent_required default true');
    check($resolved['delivery']['transport'] === 'sync', 'T4 transport default sync');
    check($resolved['site_id'] === '%env(string:CLICKTRAIL_SITE_ID)%', 'T4 site_id env placeholder default');
    check($resolved['resolver_class'] === null, 'T4 resolver_class default null');

    $resolved = $processor->processConfiguration($configuration, [[
        'site_id' => 'site-001',
        'endpoint' => 'https://ingest.example.com',
        'delivery' => ['transport' => 'async'],
        'resolver_class' => \ClickTrail\Symfony\Consent\NullConsentResolver::class,
    ]]);
    check($resolved['delivery']['transport'] === 'async', 'T4 transport override async');
    check($resolved['consent_required'] === true, 'T4 consent_required still defaulted');
    echo "T4 ok: config tree processing\n";
} else {
    echo "T4 SKIPPED: symfony/config not installed (composer install to enable)\n";
}

echo "ALL PASS (" . PHP_VERSION . ", vendor=" . var_export($hasVendor, true) . ")\n";
