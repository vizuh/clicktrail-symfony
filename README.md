[English](README.md) | [Português](README.pt-BR.md) | [Deutsch](README.de.md) | [中文](README.zh-CN.md)

<div align="center">

**clicktrail/symfony-bundle**

Request capture, consent gating, Messenger delivery and Twig helpers for deterministic campaign attribution — in any Symfony 6.4 / 7.x app.

</div>

[![CI](https://github.com/vizuh/clicktrail-symfony/actions/workflows/ci.yml/badge.svg)](https://github.com/vizuh/clicktrail-symfony/actions/workflows/ci.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/clicktrail/symfony-bundle.svg)](https://packagist.org/packages/clicktrail/symfony-bundle)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

## Index

- [Why](#why)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Reading attribution](#reading-attribution)
- [Twig helpers](#twig-helpers)
- [Async delivery via Messenger](#async-delivery-via-messenger)
- [Consent](#consent)
- [Diagnostics](#diagnostics)
- [Webhook signatures](#webhook-signatures)
- [Flex recipe plan](#flex-recipe-plan)
- [Not included (deliberate)](#not-included-deliberate)
- [How it differs](#how-it-differs)
- [Testing](#testing)
- [License](#license)

## Why

Most tracking packages store what a page showed. ClickTrail proves which campaign created the lead or sale. This bundle is a thin adapter over [`clicktrail/php-sdk`](https://github.com/vizuh/clicktrail-php), which owns the deterministic parse/classify/merge core; the bundle owns Symfony effects: request subscriber, consent gate, Messenger delivery, Twig helpers, diagnostics.

## Installation

```bash
composer require clicktrail/symfony-bundle
```

Requires PHP **>= 8.1**. (The `clicktrail/php-sdk` repository must be resolvable; a path repo and a VCS fallback are declared in this package's `composer.json`.)

## Quick start

Create `config/packages/clicktrail.yaml`:

```yaml
clicktrail:
    site_id: '%env(string:CLICKTRAIL_SITE_ID)%'
    api_key: '%env(string:CLICKTRAIL_API_KEY)%'
    endpoint: '%env(CLICKTRAIL_ENDPOINT)%'
    consent_required: true        # unknown consent = denied (default true)
    delivery:
        transport: sync           # sync|async (async routes via Messenger)
    resolver_class: null          # FQCN implementing ConsentResolverInterface
```

All values pass through Symfony env processors (`%env(...)%` placeholders). The bundle auto-registers itself; from here every request with campaign parameters builds attribution state:

```php
// 1. A visitor arrives from Google Ads on any route.
//    RequestSubscriber merges the touch on kernel.request (high priority):

// 2. In a controller or service:
use ClickTrail\Symfony\Attribution\ContextHolder;

public function form(ContextHolder $holder): Response
{
    $context = $holder->get();       // AttributionContext for this request (or null)
    // $context?->attribution->first->source === 'google',
    // $context?->attribution->first->clickIds['gclid'] set — persisted to the
    // session ONLY when consent permits analytics storage; unknown = denied.
}

// 3. On conversion, dispatch delivery:
$this->bus->dispatch(new \ClickTrail\Symfony\Messenger\DeliverEventsMessage());
// handler flushes the SDK BatchClient — batched POST to endpoint with
// idempotency keys; nothing is sent during the request itself.
```

A direct visit afterwards changes nothing — first touch stays, stored last touch persists. That is the SDK's merge law, tested, not promised.

## Reading attribution

`Attribution\ContextHolder` is a stateless read-side accessor for the current request's `AttributionContext`. The subscriber stores it as a request attribute, so controllers, services, and event listeners read the same merged state.

## Twig helpers

```twig
{# renders the first-party loader <script> tag from config #}
{{ clicktrail_head(context) }}

{# hidden attribution inputs inside a <form>, so the server-side submit
   carries source / click IDs verbatim #}
{{ clicktrail_hidden_attribution_inputs(attribution) }}
```

Render-only extensions; all output is escaped with `htmlspecialchars(..., ENT_QUOTES)`.

## Async delivery via Messenger

Route the delivery message to your transport:

```yaml
framework:
    messenger:
        routing:
            ClickTrail\Symfony\Messenger\DeliverEventsMessage: async
```

The handler flushes the SDK `BatchClient`. The container must provide PSR-18 client/request/stream factories (e.g. `symfony/http-client`). Delivery never happens during the request unless configured.

## Consent

ClickTrail is a consent consumer, not a CMP. Set `resolver_class` to an FQCN implementing `ConsentResolverInterface`, or override the alias with your own CMP adapter. Until then the shipped `NullConsentResolver` returns an unknown snapshot, treated as denied everywhere: no identifiers are persisted and no events are delivered.

## Diagnostics

```bash
php bin/console clicktrail:diagnose
```

Prints the effective configuration (secrets masked) and runs a local signature self-test.

## Webhook signatures

Verify ClickTrail webhook callbacks with constant-time SHA-256 comparison:

```php
\ClickTrail\Symfony\Support\WebhookSignature::verify($payload, $signatureHeader, $secret);
// === true only when the signature matches; constant-time, no timing leak
```

## Flex recipe plan

A `symfony/recipes-contrib` pull request providing the default `config/packages/clicktrail.yaml` skeleton is planned **post-release** — the recipe cannot be submitted before the package has a tagged version. Until then, create the config file manually as shown above.

## Not included (deliberate)

**Doctrine integration** (persisting attribution snapshots to entities, doctrine event listeners) is intentionally out of scope here. It is planned as an optional follow-up package so apps that do not use ORM keep a dependency-free install.

## How it differs

- **DIY UTM-to-cookie snippets** store whatever the URL carried, unvalidated. ClickTrail applies deterministic first/last-touch merge laws validated by golden fixtures shared with our WordPress and GTM engines, gates persistence on consent, and delivers batched events with idempotency keys.
- **DirectoryTree/Metrics** counts anonymous events. Complementary — ClickTrail connects campaigns to identities and revenue, not page-view counters.

See `../docs/COMPETITOR-NOTES.md` for the full analysis.

## Testing

```bash
php tests/_runner.php                 # full suite, standalone (no kernel boot)
```

CI lints all PHP files on PHP 8.1–8.3 (`.github/workflows/ci.yml`, canonical template from `../templates/ci-php-matrix.yml`).

## License

MIT — see [LICENSE](LICENSE).
