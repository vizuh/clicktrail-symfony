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

// PSR-18/17/log interfaces for the delivery sections: composer vendor first,
// else the sibling SDK checkout's vendor (hermetic runs without composer install).
if (!interface_exists(\Psr\Http\Client\ClientInterface::class)) {
    $sdkAutoload = (getenv('CLICKTRAIL_SDK_ROOT') ?: dirname(__DIR__, 2) . '/clicktrail-php') . '/vendor/autoload.php';
    if (is_file($sdkAutoload)) {
        require $sdkAutoload;
    }
}
$hasPsr = interface_exists(\Psr\Http\Client\ClientInterface::class)
    && interface_exists(\Psr\Http\Message\RequestFactoryInterface::class)
    && interface_exists(\Psr\Log\LoggerInterface::class);

function check(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
}

/**
 * Recording PSR-18 client that is also its own PSR-17 request/stream factory.
 * Minimal PSR-7 fakes because the bundle's dependencies ship no PSR-7
 * implementation. $statuses: response codes to return in order (then 202).
 */
function ct_fake_http(): object
{
    $stream = new class ('') implements \Psr\Http\Message\StreamInterface {
        public function __construct(private string $data) {}
        public function with(string $data): static { $c = clone $this; $c->data = $data; return $c; }
        public function __toString(): string { return $this->data; }
        public function close(): void {}
        public function detach() { return null; }
        public function getSize(): ?int { return strlen($this->data); }
        public function tell(): int { return 0; }
        public function eof(): bool { return true; }
        public function isSeekable(): bool { return false; }
        public function seek($offset, $whence = SEEK_SET): void {}
        public function rewind(): void {}
        public function isWritable(): bool { return false; }
        public function write($string): int { return 0; }
        public function isReadable(): bool { return true; }
        public function read($length): string { return $this->data; }
        public function getContents(): string { return $this->data; }
        public function getMetadata($key = null) { return null; }
    };

    $message = new class ($stream) implements \Psr\Http\Message\RequestInterface, \Psr\Http\Message\ResponseInterface {
        public string $method = 'GET';
        public string $uri = '';
        public int $status = 200;
        /** @var array<string, array<int, string>> */
        public array $headers = [];
        public function __construct(public \Psr\Http\Message\StreamInterface $body) {}
        public function getProtocolVersion(): string { return '1.1'; }
        public function withProtocolVersion($version): static { return $this; }
        public function getHeaders(): array { return $this->headers; }
        public function hasHeader($name): bool { return isset($this->headers[strtolower($name)]); }
        public function getHeader($name): array { return $this->headers[strtolower($name)] ?? []; }
        public function getHeaderLine($name): string { return implode(', ', $this->getHeader($name)); }
        public function withHeader($name, $value): static { $c = clone $this; $c->headers[strtolower($name)] = (array) $value; return $c; }
        public function withAddedHeader($name, $value): static { $c = clone $this; $c->headers[strtolower($name)] = array_merge($c->getHeader($name), (array) $value); return $c; }
        public function withoutHeader($name): static { $c = clone $this; unset($c->headers[strtolower($name)]); return $c; }
        public function getBody(): \Psr\Http\Message\StreamInterface { return $this->body; }
        public function withBody($body): static { $c = clone $this; $c->body = $body; return $c; }
        public function getRequestTarget(): string { return '/'; }
        public function withRequestTarget($requestTarget): static { return $this; }
        public function getMethod(): string { return $this->method; }
        public function withMethod($method): static { $c = clone $this; $c->method = $method; return $c; }
        public function getUri(): \Psr\Http\Message\UriInterface { throw new \LogicException('not used by BatchClient'); }
        public function withUri($uri, $preserveHost = false): static { return $this; }
        public function getStatusCode(): int { return $this->status; }
        public function withStatus($code, $reasonPhrase = ''): static { $c = clone $this; $c->status = $code; return $c; }
        public function getReasonPhrase(): string { return ''; }
    };

    return new class ($message, $stream) implements \Psr\Http\Client\ClientInterface, \Psr\Http\Message\RequestFactoryInterface, \Psr\Http\Message\StreamFactoryInterface {
        /** @var array<int, object> */
        public array $sent = [];
        /** @var array<int, int> */
        public array $statuses = [];
        public function __construct(private object $message, private object $stream) {}
        public function createRequest($method, $uri): \Psr\Http\Message\RequestInterface { $r = $this->message->withMethod($method); $r->uri = (string) $uri; return $r; }
        public function createStream($content = ''): \Psr\Http\Message\StreamInterface { return $this->stream->with($content); }
        public function createStreamFromFile($filename, $mode = 'r'): \Psr\Http\Message\StreamInterface { throw new \LogicException('not used by BatchClient'); }
        public function createStreamFromResource($resource): \Psr\Http\Message\StreamInterface { throw new \LogicException('not used by BatchClient'); }
        public function sendRequest($request): \Psr\Http\Message\ResponseInterface { $this->sent[] = $request; return $this->message->withStatus(array_shift($this->statuses) ?? 202); }
        /** @return array<int, array<int, string>> idempotency keys of each sent request body */
        public function sentKeys(): array { return array_map(static fn ($r): array => array_column(json_decode((string) $r->getBody(), true), 'idempotency_key'), $this->sent); }
    };
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

// --- T5: services.xml BatchClient arguments map to the parameters they name --
$xml = simplexml_load_file($root . '/src/Resources/config/services.xml');
$xml->registerXPathNamespace('s', 'http://symfony.com/schema/dic/services');
$params = [];
foreach ((new \ReflectionClass(\ClickTrail\Client\BatchClient::class))->getConstructor()->getParameters() as $p) {
    $params['$' . $p->getName()] = $p;
}
$clientDefs = $xml->xpath('//s:service[@class="ClickTrail\Client\BatchClient"]');
check(count($clientDefs) === 2, 'T5 app client + pristine delivery client definitions: ' . count($clientDefs));
foreach ($clientDefs as $def) {
    $id = (string) $def['id'];
    $wired = [];
    foreach ($def->children('http://symfony.com/schema/dic/services')->argument as $arg) {
        $attr = $arg->attributes();
        $key = (string) $attr['key'];
        check(isset($params[$key]), "T5 $id argument key '$key' names a BatchClient parameter");
        $type = $params[$key]->getType();
        if ((string) $attr['type'] === 'service') {
            check(!$type->isBuiltin(), "T5 $id service argument $key goes to a class-typed parameter ($type)");
            check((string) $attr['on-invalid'] !== 'null' || $type->allowsNull(), "T5 $id on-invalid=null only on nullable $key");
        } else {
            check($type->getName() === 'string' && preg_match('/^%[a-z_.]+%$/', (string) $arg) === 1, "T5 $id parameter argument $key goes to a string parameter");
        }
        $wired[$key] = ['value' => (string) $arg, 'id' => (string) $attr['id']];
    }
    foreach ($params as $name => $p) {
        check($p->isOptional() || isset($wired[$name]), "T5 $id required parameter $name wired");
    }
    check(($wired['$apiKey']['value'] ?? '') === '%clicktrail.api_key%', "T5 $id \$apiKey receives %clicktrail.api_key%");
    check(($wired['$logger']['id'] ?? '') === 'logger', "T5 $id \$logger receives the logger service");
}
$alias = $xml->xpath('//s:service[@id="ClickTrail\Client\BatchClient"]');
check(count($alias) === 1 && (string) $alias[0]['alias'] === 'clicktrail.batch_client' && (string) $alias[0]['public'] === 'true', 'T5 public FQCN alias to clicktrail.batch_client');
echo "T5 ok: BatchClient wiring\n";

// --- T6: handler delivers exactly the payloads the message carries ----------
if ($hasPsr) {
    $newClient = static fn (object $http): \ClickTrail\Client\BatchClient => new \ClickTrail\Client\BatchClient(
        siteId: 'site-001',
        endpoint: 'https://ingest.example.com/batch',
        http: $http,
        requestFactory: $http,
        streamFactory: $http,
        apiKey: 'ck_test',
    );

    $http = ct_fake_http();
    $app = $newClient($http);
    $app->conversion('evt_1', ['value' => 10]);
    $app->conversion('evt_2', ['value' => 20]);
    $message = unserialize(serialize(new \ClickTrail\Symfony\Messenger\DeliverEventsMessage($app->pending())));
    check($message->events === $app->pending(), 'T6 payloads survive the transport serialization boundary');

    $handler = new \ClickTrail\Symfony\Messenger\DeliverEventsMessageHandler($newClient($http));
    $handler($message);
    check(count($http->sent) === 1, 'T6 one HTTP request for the message: ' . count($http->sent));
    check($http->sent[0]->getHeaderLine('Authorization') === 'Bearer ck_test', 'T6 Authorization: Bearer <configured key>');
    check($http->sentKeys()[0] === ['evt_1', 'evt_2'], 'T6 request carries exactly the message payloads');

    // A failed message must not leave batches behind for the next message.
    $http = ct_fake_http();
    $handler = new \ClickTrail\Symfony\Messenger\DeliverEventsMessageHandler($newClient($http));
    $big = [];
    for ($i = 0; $i < 21; $i++) {
        $big[] = ['idempotency_key' => "a$i", 'site_id' => 'site-001', 'event' => ['name' => 'conversion']];
    }
    $http->statuses = [400];
    $threw = false;
    try {
        $handler(new \ClickTrail\Symfony\Messenger\DeliverEventsMessage($big));
    } catch (\ClickTrail\Client\Exception\PermanentException) {
        $threw = true;
    }
    check($threw, 'T6 permanent ingestion failure propagates to Messenger');
    $handler(new \ClickTrail\Symfony\Messenger\DeliverEventsMessage([['idempotency_key' => 'b0', 'site_id' => 'site-001', 'event' => ['name' => 'conversion']]]));
    check(count($http->sent) === 2 && $http->sentKeys()[1] === ['b0'], 'T6 next message sends only its own payloads: ' . json_encode($http->sentKeys()));
    $handler(new \ClickTrail\Symfony\Messenger\DeliverEventsMessage([]));
    check(count($http->sent) === 2, 'T6 empty message sends nothing');
    echo "T6 ok: handler delivery\n";
} else {
    echo "T6 SKIPPED: PSR http/log interfaces not installed (composer install to enable)\n";
}

// --- T7: compiled container wiring (needs symfony/dependency-injection) -----
if ($hasPsr && $hasVendor && class_exists(\Symfony\Component\DependencyInjection\ContainerBuilder::class)) {
    $cb = new \Symfony\Component\DependencyInjection\ContainerBuilder();
    (new \ClickTrail\Symfony\DependencyInjection\ClickTrailExtension())->load([[
        'site_id' => 'site-001',
        'api_key' => 'ck_live_test',
        'endpoint' => 'https://ingest.example.com/batch',
    ]], $cb);
    $psrIds = [\Psr\Http\Client\ClientInterface::class, \Psr\Http\Message\RequestFactoryInterface::class, \Psr\Http\Message\StreamFactoryInterface::class];
    foreach ($psrIds as $psrId) {
        $cb->register($psrId)->setSynthetic(true)->setPublic(true);
    }
    $cb->register('logger', \Psr\Log\NullLogger::class);
    $cb->getDefinition(\ClickTrail\Symfony\Messenger\DeliverEventsMessageHandler::class)->setPublic(true);
    $cb->compile();
    $http = ct_fake_http();
    foreach ($psrIds as $psrId) {
        $cb->set($psrId, $http);
    }

    $app = $cb->get(\ClickTrail\Client\BatchClient::class);
    check($app instanceof \ClickTrail\Client\BatchClient && $app === $cb->get(\ClickTrail\Client\BatchClient::class), 'T7 FQCN alias resolves to one shared app client');
    $app->conversion('evt_c', ['value' => 5]);
    // Default Messenger routing handles the message in-process (sync).
    $cb->get(\ClickTrail\Symfony\Messenger\DeliverEventsMessageHandler::class)(new \ClickTrail\Symfony\Messenger\DeliverEventsMessage($app->pending()));
    check(count($http->sent) === 1, 'T7 one request from the container-built handler: ' . count($http->sent));
    check($http->sent[0]->getHeaderLine('Authorization') === 'Bearer ck_live_test', 'T7 configured api_key reaches the Authorization header');
    check($http->sentKeys()[0] === ['evt_c'], 'T7 in-process handling sends each payload once: ' . json_encode($http->sentKeys()));
    echo "T7 ok: compiled container delivery\n";
} else {
    echo "T7 SKIPPED: symfony/dependency-injection not installed (composer install to enable)\n";
}

echo "ALL PASS (" . PHP_VERSION . ", vendor=" . var_export($hasVendor, true) . ")\n";
