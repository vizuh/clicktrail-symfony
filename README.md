# clicktrail/symfony-bundle

Symfony bundle for ClickTrail attribution: request capture, consent gating,
Messenger delivery, Twig helpers and diagnostics — a thin adapter over
[`clicktrail/php-sdk`](https://github.com/vizuh/clicktrail-php), which owns the
deterministic parse/classify/merge core.

Supports Symfony **6.4 / 7.x**, PHP **>= 8.1**.

## Install

```bash
composer require clicktrail/symfony-bundle
```

(Requires the `clicktrail/php-sdk` repository to be resolvable; a path repo
and a VCS fallback are declared in this package's composer.json.)

## Configuration

```yaml
# config/packages/clicktrail.yaml
clicktrail:
    site_id: '%env(string:CLICKTRAIL_SITE_ID)%'
    api_key: '%env(string:CLICKTRAIL_API_KEY)%'
    endpoint: '%env(CLICKTRAIL_ENDPOINT)%'
    consent_required: true        # unknown consent = denied (default true)
    delivery:
        transport: sync           # sync|async (async routes via Messenger)
    resolver_class: null          # FQCN implementing ConsentResolverInterface
```

All values pass through Symfony env processors (`%env(...)%` placeholders).

### Async delivery

Route the delivery message to your transport:

```yaml
framework:
    messenger:
        routing:
            ClickTrail\Symfony\Messenger\DeliverEventsMessage: async
```

## What it wires

- `RequestSubscriber` — on `kernel.request` (high priority) it builds an
  `AttributionInput` from the Request (query params, host, full URL, Referer),
  merges via the SDK's `TouchMerger::observe`, gates persistence through
  `ConsentResolverInterface` (unknown = denied), persists `StoredState` to the
  session only when permitted, and stores an `AttributionContext` as a request
  attribute.
- `Attribution\ContextHolder` — stateless read-side accessor for the current
  request's `AttributionContext`.
- `Consent\NullConsentResolver` — safe default resolver; set
  `resolver_class` or override the alias to integrate your CMP.
- `Messenger\DeliverEventsMessage` + handler — flushes the SDK `BatchClient`.
- `Twig\ClickTrailExtension` — render-only `clicktrail_head(context)` and
  `clicktrail_hidden_attribution_inputs(attribution)`; all output escaped with
  `htmlspecialchars(..., ENT_QUOTES)`.
- `Console\DiagnoseCommand` — `php bin/console clicktrail:diagnose` prints the
  effective config (secrets masked) and runs a local signature self-test.
- `Support\WebhookSignature` — constant-time SHA-256 signature verification.

The bundle expects PSR-18 client/request/stream factories in the container for
the batch transport (e.g. `symfony/http-client`). Delivery never happens
during the request unless configured.

## Flex recipe plan

A `symfony/recipes-contrib` pull request providing the default
`config/packages/clicktrail.yaml` skeleton is planned **post-release** — the
recipe cannot be submitted before the package has a tagged version. Until then,
create the config file manually as shown above.

## Not included (deliberate)

- **Doctrine integration** (persisting attribution snapshots to entities,
  doctrine event listeners): intentionally out of scope here. Planned as an
  optional follow-up package so apps that do not use ORM keep a dependency-free
  install.

## Testing

Standalone runner (no kernel boot):

```bash
podman run --rm -v "$PWD":/app:Z wordpress:php8.3-apache php /app/tests/_runner.php
```

CI lints all PHP files on PHP 8.1–8.3 (`.github/workflows/ci.yml`, canonical
template from php-marketplaces/templates/ci-php-matrix.yml).

## License

MIT — see [LICENSE](LICENSE).
