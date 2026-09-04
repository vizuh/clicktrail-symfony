[English](README.md) | [Português](README.pt-BR.md) | [Deutsch](README.de.md) | [中文](README.zh-CN.md)

<div align="center">

**clicktrail/symfony-bundle**

Captura de request, gating por consentimento, entrega via Messenger e helpers Twig para atribuição determinística de campanhas — em qualquer app Symfony 6.4 / 7.x.

</div>

[![CI](https://github.com/vizuh/clicktrail-symfony/actions/workflows/ci.yml/badge.svg)](https://github.com/vizuh/clicktrail-symfony/actions/workflows/ci.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/clicktrail/symfony-bundle.svg)](https://packagist.org/packages/clicktrail/symfony-bundle)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

## Índice

- [Por quê](#por-quê)
- [Instalação](#instalação)
- [Início rápido](#início-rápido)
- [Lendo a atribuição](#lendo-a-atribuição)
- [Helpers Twig](#helpers-twig)
- [Entrega assíncrona via Messenger](#entrega-assíncrona-via-messenger)
- [Consentimento](#consentimento)
- [Diagnóstico](#diagnóstico)
- [Assinaturas de webhook](#assinaturas-de-webhook)
- [Plano de receita Flex](#plano-de-receita-flex)
- [Não incluído (de propósito)](#não-incluído-de-propósito)
- [Como se diferencia](#como-se-diferencia)
- [Testes](#testes)
- [Licença](#licença)

## Por quê

A maioria dos pacotes de tracking guarda o que a página mostrou. O ClickTrail prova qual campanha criou o lead ou a venda. Este bundle é um adaptador fino sobre o [`clicktrail/php-sdk`](https://github.com/vizuh/clicktrail-php), que detém o núcleo determinístico de parse/classify/merge; o bundle cuida dos efeitos Symfony: subscriber de request, gate de consentimento, entrega via Messenger, helpers Twig e diagnóstico.

## Instalação

```bash
composer require clicktrail/symfony-bundle
```

Requer PHP **>= 8.1**. O bundle resolve o `clicktrail/php-sdk` como uma dependência Composer pública e estável.

## Início rápido

Crie `config/packages/clicktrail.yaml`:

```yaml
clicktrail:
    site_id: '%env(string:CLICKTRAIL_SITE_ID)%'
    api_key: '%env(string:CLICKTRAIL_API_KEY)%'
    endpoint: '%env(CLICKTRAIL_ENDPOINT)%'
    consent_required: true        # consentimento desconhecido = negado (padrão true)
    delivery:
        transport: sync           # sync|async (async roteia pelo Messenger)
    resolver_class: null          # FQCN implementando ConsentResolverInterface
```

Todos os valores passam pelos env processors do Symfony (placeholders `%env(...)%`). O bundle se registra automaticamente; daí em diante, toda request com parâmetros de campanha constrói estado de atribuição:

```php
// 1. Um visitante chega do Google Ads em qualquer rota.
//    O RequestSubscriber faz o merge do touch no kernel.request (alta prioridade).

// 2. Num controller ou serviço:
use ClickTrail\Symfony\Attribution\ContextHolder;

public function form(ContextHolder $holder): Response
{
    $context = $holder->get();       // AttributionContext desta request (ou null)
    // $context?->attribution->first->source === 'google',
    // $context?->attribution->first->clickIds['gclid'] preenchido — persistido na
    // sessão SOMENTE quando o consentimento permite analytics storage;
    // desconhecido = negado.
}

// 3. Na conversão, despache a entrega:
$this->bus->dispatch(new \ClickTrail\Symfony\Messenger\DeliverEventsMessage());
// o handler faz flush do BatchClient do SDK — POST em lote para o endpoint com
// idempotency keys; nada é enviado durante a própria request.
```

Uma visita direta depois não muda nada — o first touch permanece, o last touch armazenado persiste. Essa é a merge law do SDK: testada, não prometida.

## Lendo a atribuição

O `Attribution\ContextHolder` é um acessor de leitura stateless para o `AttributionContext` da request atual. O subscriber o guarda como request attribute, então controllers, serviços e event listeners leem o mesmo estado mesclado.

## Helpers Twig

```twig
{# renderiza a tag <script> do loader first-party a partir da config #}
{{ clicktrail_head(context) }}

{# inputs ocultos de atribuição dentro de um <form>, para que o submit
   no servidor carregue source / click IDs verbatim #}
{{ clicktrail_hidden_attribution_inputs(attribution) }}
```

Extensões somente de renderização; toda saída é escapada com `htmlspecialchars(..., ENT_QUOTES)`.

## Entrega assíncrona via Messenger

Roteie a mensagem de entrega para o seu transport:

```yaml
framework:
    messenger:
        routing:
            ClickTrail\Symfony\Messenger\DeliverEventsMessage: async
```

O handler faz flush do `BatchClient` do SDK. O container precisa prover factories PSR-18 de client/request/stream (ex.: `symfony/http-client`). A entrega nunca acontece durante a request, salvo configuração explícita.

## Consentimento

O ClickTrail é consumidor de consentimento, não um CMP. Defina `resolver_class` com um FQCN que implemente `ConsentResolverInterface`, ou sobrescreva o alias com o adapter do seu próprio CMP. Até lá, o `NullConsentResolver` embarcado devolve um snapshot desconhecido, tratado como negado em toda parte: nenhum identificador é persistido e nenhum evento é entregue.

## Diagnóstico

```bash
php bin/console clicktrail:diagnose
```

Imprime a configuração efetiva (segredos mascarados) e roda um self-test local de assinatura.

## Assinaturas de webhook

Verifique callbacks de webhook do ClickTrail com comparação SHA-256 em tempo constante:

```php
\ClickTrail\Symfony\Support\WebhookSignature::verify($payload, $signatureHeader, $secret);
// === true somente quando a assinatura bate; tempo constante, sem timing leak
```

## Plano de receita Flex

Um pull request para o `symfony/recipes-contrib` com o esqueleto padrão de `config/packages/clicktrail.yaml` está planejado **pós-release** — a receita não pode ser submetida antes que o pacote tenha uma versão tagada. Até lá, crie o arquivo de config manualmente como mostrado acima.

## Não incluído (de propósito)

A **integração com Doctrine** (persistir snapshots de atribuição em entidades, doctrine event listeners) fica fora de escopo aqui, de propósito. Está planejada como um pacote opcional posterior, para que apps sem ORM mantenham uma instalação livre de dependências extras.

## Como se diferencia

- **Snippets DIY de UTM-para-cookie** guardam qualquer coisa que a URL trouxesse, sem validação. O ClickTrail aplica leis determinísticas de merge first/last-touch validadas por golden fixtures compartilhadas com nossos engines WordPress e GTM, gate de persistência por consentimento e entrega em lote com idempotency keys.
- **DirectoryTree/Metrics** conta eventos anônimos. Complementar — o ClickTrail conecta campanhas a identidades e receita.

Veja `../docs/COMPETITOR-NOTES.md` para a análise completa.

## Testes

```bash
php tests/_runner.php                 # suite completa, standalone (sem boot de kernel)
```

O CI faz lint de todos os arquivos PHP no PHP 8.1–8.3 (`.github/workflows/ci.yml`, template canônico de `../templates/ci-php-matrix.yml`).

## Licença

MIT — veja [LICENSE](LICENSE).
