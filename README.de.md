[English](README.md) | [Português](README.pt-BR.md) | [Deutsch](README.de.md) | [中文](README.zh-CN.md)

<div align="center">

**clicktrail/symfony-bundle**

Request-Capture, Consent-Gating, Messenger-Zustellung und Twig-Helper für deterministische Kampagnen-Attribution — in jeder Symfony-6.4-/7.x-Anwendung.

</div>

[![CI](https://github.com/vizuh/clicktrail-symfony/actions/workflows/ci.yml/badge.svg)](https://github.com/vizuh/clicktrail-symfony/actions/workflows/ci.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/clicktrail/symfony-bundle.svg)](https://packagist.org/packages/clicktrail/symfony-bundle)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

## Index

- [Warum](#warum)
- [Installation](#installation)
- [Schnellstart](#schnellstart)
- [Attribution lesen](#attribution-lesen)
- [Twig-Helper](#twig-helper)
- [Asynchrone Zustellung via Messenger](#asynchrone-zustellung-via-messenger)
- [Consent](#consent)
- [Diagnose](#diagnose)
- [Webhook-Signaturen](#webhook-signaturen)
- [Flex-Rezept-Plan](#flex-rezept-plan)
- [Nicht enthalten (bewusst)](#nicht-enthalten-bewusst)
- [Unterschiede](#unterschiede)
- [Testing](#testing)
- [Lizenz](#lizenz)

## Warum

Die meisten Tracking-Pakete speichern, was eine Seite angezeigt hat. ClickTrail beweist, welche Kampagne den Lead oder Verkauf erzeugt hat. Dieses Bundle ist ein schlanker Adapter über [`clicktrail/php-sdk`](https://github.com/vizuh/clicktrail-php), das den deterministischen parse/classify/merge-Kern besitzt; das Bundle übernimmt die Symfony-Effekte: Request-Subscriber, Consent-Gate, Messenger-Zustellung, Twig-Helper und Diagnose.

## Installation

```bash
composer require clicktrail/symfony-bundle
```

Benötigt PHP **>= 8.1**. Das Bundle löst `clicktrail/php-sdk` als stabile öffentliche Composer-Abhängigkeit auf.

## Schnellstart

Erstellen Sie `config/packages/clicktrail.yaml`:

```yaml
clicktrail:
    site_id: '%env(string:CLICKTRAIL_SITE_ID)%'
    api_key: '%env(string:CLICKTRAIL_API_KEY)%'
    endpoint: '%env(CLICKTRAIL_ENDPOINT)%'
    consent_required: true        # unbekanntes Consent = verweigert (Standard true)
    delivery:
        transport: sync           # sync|async (async läuft über Messenger)
    resolver_class: null          # FQCN, der ConsentResolverInterface implementiert
```

Alle Werte laufen durch die Env-Prozessoren von Symfony (`%env(...)%`-Platzhalter). Das Bundle registriert sich automatisch; ab hier baut jede Anfrage mit Kampagnen-Parametern Attribution-State auf:

```php
// 1. Ein Besucher kommt über Google Ads auf einer beliebigen Route.
//    Der RequestSubscriber führt den Touch auf kernel.request zusammen (hohe Priorität).

// 2. In einem Controller oder Service:
use ClickTrail\Symfony\Attribution\ContextHolder;

public function form(ContextHolder $holder): Response
{
    $context = $holder->get();       // AttributionContext dieses Requests (oder null)
    // $context?->attribution->first->source === 'google',
    // gesetztes $context?->attribution->first->clickIds['gclid'] — in der Session
    // gespeichert NUR wenn Consent Analytics-Storage erlaubt; unbekannt = verweigert.
}

// 3. Bei der Konversion die Zustellung dispatchen:
$this->bus->dispatch(new \ClickTrail\Symfony\Messenger\DeliverEventsMessage());
// Der Handler flushed den BatchClient des SDK — POST im Batch an den Endpoint mit
// Idempotency-Keys; nichts wird während des Requests selbst gesendet.
```

Ein direkter Besuch danach ändert nichts — der First Touch bleibt, der gespeicherte Last Touch bleibt erhalten. Das ist das Merge-Gesetz des SDK: getestet, nicht versprochen.

## Attribution lesen

`Attribution\ContextHolder` ist ein zustandsloser Lesezugriff auf den `AttributionContext` des aktuellen Requests. Der Subscriber legt ihn als Request-Attribute ab, sodass Controller, Services und Event-Listener denselben zusammengeführten State lesen.

## Twig-Helper

```twig
{# rendert das First-Party-Loader-<script>-Tag aus der Config #}
{{ clicktrail_head(context) }}

{# versteckte Attribution-Inputs innerhalb eines <form>, damit der
   serverseitige Submit source / Click-IDs unverändert trägt #}
{{ clicktrail_hidden_attribution_inputs(attribution) }}
```

Rein rendernde Extensions; alle Ausgabe ist mit `htmlspecialchars(..., ENT_QUOTES)` escaped.

## Asynchrone Zustellung via Messenger

Routen Sie die Delivery-Message an Ihren Transport:

```yaml
framework:
    messenger:
        routing:
            ClickTrail\Symfony\Messenger\DeliverEventsMessage: async
```

Der Handler flushed den `BatchClient` des SDK. Der Container muss PSR-18-Client-/Request-/Stream-Factories bereitstellen (z. B. `symfony/http-client`). Eine Zustellung findet nie während des Requests statt, sofern nicht konfiguriert.

## Consent

ClickTrail ist ein Consent-Konsument, kein CMP. Setzen Sie `resolver_class` auf einen FQCN, der `ConsentResolverInterface` implementiert, oder überschreiben Sie den Alias mit Ihrem eigenen CMP-Adapter. Bis dahin liefert der mitgelieferte `NullConsentResolver` einen unbekannten Snapshot, der überall als verweigert gilt: Es werden keine Identifikatoren persistiert und keine Events zugestellt.

## Diagnose

```bash
php bin/console clicktrail:diagnose
```

Gibt die effektive Konfiguration aus (Secrets maskiert) und führt einen lokalen Signatur-Selbsttest durch.

## Webhook-Signaturen

Verifizieren Sie ClickTrail-Webhook-Callbacks mit SHA-256-Vergleich in konstanter Zeit:

```php
\ClickTrail\Symfony\Support\WebhookSignature::verify($payload, $signatureHeader, $secret);
// === true nur bei passender Signatur; konstante Zeit, kein Timing-Leak
```

## Flex-Rezept-Plan

Ein Pull Request für `symfony/recipes-contrib` mit dem Standard-Skeleton von `config/packages/clicktrail.yaml` ist **nach dem Release** geplant — das Rezept kann erst eingereicht werden, wenn das Paket eine getaggte Version hat. Bis dahin erstellen Sie die Config-Datei manuell wie oben gezeigt.

## Nicht enthalten (bewusst)

Die **Doctrine-Integration** (Persistenz von Attribution-Snapshots auf Entities, Doctrine-Event-Listener) ist hier bewusst außerhalb des Scopes. Sie ist als optionales Folgepaket geplant, damit Apps ohne ORM eine installationsfreundliche, abhängigkeitsfreie Basis behalten.

## Unterschiede

- **DIY-UTM-zu-Cookie-Snippets** speichern alles, was die URL mitbrachte, unvalidiert. ClickTrail wendet deterministische First-/Last-Touch-Merge-Gesetze an, validiert durch Golden Fixtures, die unsere WordPress- und GTM-Engines teilen, prüft die Persistenz gegen Consent und stellt Events gebündelt mit Idempotency-Keys zu.
- **DirectoryTree/Metrics** zählt anonyme Events. Komplementär — ClickTrail verbindet Kampagnen mit Identitäten und Umsatz.

Siehe `../docs/COMPETITOR-NOTES.md` für die vollständige Analyse.

## Testing

```bash
php tests/_runner.php                 # komplette Suite, standalone (kein Kernel-Boot)
```

Die CI lintet alle PHP-Dateien unter PHP 8.1–8.3 (`.github/workflows/ci.yml`, kanonisches Template aus `../templates/ci-php-matrix.yml`).

## Lizenz

MIT — siehe [LICENSE](LICENSE).
