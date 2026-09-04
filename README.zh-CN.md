[English](README.md) | [Português](README.pt-BR.md) | [Deutsch](README.de.md) | [中文](README.zh-CN.md)

<div align="center">

**clicktrail/symfony-bundle**

面向确定性广告归因的请求捕获、同意门控、Messenger 投递与 Twig 辅助函数——适用于任意 Symfony 6.4 / 7.x 应用。

</div>

[![CI](https://github.com/vizuh/clicktrail-symfony/actions/workflows/ci.yml/badge.svg)](https://github.com/vizuh/clicktrail-symfony/actions/workflows/ci.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/clicktrail/symfony-bundle.svg)](https://packagist.org/packages/clicktrail/symfony-bundle)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

## 目录

- [为什么](#为什么)
- [安装](#安装)
- [快速上手](#快速上手)
- [读取归因数据](#读取归因数据)
- [Twig 辅助函数](#twig-辅助函数)
- [经 Messenger 异步投递](#经-messenger-异步投递)
- [同意管理](#同意管理)
- [诊断](#诊断)
- [Webhook 签名](#webhook-签名)
- [Flex recipe 计划](#flex-recipe-计划)
- [刻意未包含的内容](#刻意未包含的内容)
- [差异对比](#差异对比)
- [测试](#测试)
- [许可协议](#许可协议)

## 为什么

大多数追踪包只记录页面展示了什么。ClickTrail 证明是哪个广告系列创造了线索或成交。本 bundle 是 [`clicktrail/php-sdk`](https://github.com/vizuh/clicktrail-php) 之上的轻量适配层，SDK 持有确定性的 parse/classify/merge 核心；bundle 负责 Symfony 侧的效应：请求订阅器、同意门控、Messenger 投递、Twig 辅助函数和诊断。

## 安装

```bash
composer require clicktrail/symfony-bundle
```

要求 PHP **>= 8.1**。bundle 通过 Composer 将 `clicktrail/php-sdk` 作为公开稳定依赖解析。

## 快速上手

创建 `config/packages/clicktrail.yaml`：

```yaml
clicktrail:
    site_id: '%env(string:CLICKTRAIL_SITE_ID)%'
    api_key: '%env(string:CLICKTRAIL_API_KEY)%'
    endpoint: '%env(CLICKTRAIL_ENDPOINT)%'
    consent_required: true        # 同意状态未知 = 拒绝（默认 true）
    delivery:
        transport: sync           # sync|async（async 经 Messenger 路由）
    resolver_class: null          # 实现 ConsentResolverInterface 的 FQCN
```

所有值都经过 Symfony 的 env 处理器（`%env(...)%` 占位符）。bundle 自动注册；此后每个携带广告参数的请求都会构建归因状态：

```php
// 1. 访客从 Google Ads 到达任意路由。
//    RequestSubscriber 在 kernel.request（高优先级）完成触点合并：

// 2. 在控制器或服务中：
use ClickTrail\Symfony\Attribution\ContextHolder;

public function form(ContextHolder $holder): Response
{
    $context = $holder->get();       // 本次请求的 AttributionContext（或 null）
    // $context?->attribution->first->source === 'google'，
    // $context?->attribution->first->clickIds['gclid'] 已写入——仅当同意策略允许
    // analytics storage 时才持久化到会话；未知 = 拒绝。
}

// 3. 转化发生时，派发投递：
$this->bus->dispatch(new \ClickTrail\Symfony\Messenger\DeliverEventsMessage());
// handler 刷新 SDK 的 BatchClient——批量 POST 到端点并带幂等键；
// 请求本身期间不发送任何数据。
```

之后的直接访问不会改变任何东西——first touch 保持不变，已存储的 last touch 继续保留。这是 SDK 的合并法则：经过测试，而非口头承诺。

## 读取归因数据

`Attribution\ContextHolder` 是对当前请求 `AttributionContext` 的无状态只读访问器。订阅器将其存为请求属性，因此控制器、服务和事件监听器读到的是同一份合并后的状态。

## Twig 辅助函数

```twig
{# 根据配置渲染第一方加载器的 <script> 标签 #}
{{ clicktrail_head(context) }}

{# 表单内的隐藏归因字段，让服务端收到的提交原样携带 source / 点击 ID #}
{{ clicktrail_hidden_attribution_inputs(attribution) }}
```

纯渲染扩展；所有输出均以 `htmlspecialchars(..., ENT_QUOTES)` 转义。

## 经 Messenger 异步投递

将投递消息路由到你的传输层：

```yaml
framework:
    messenger:
        routing:
            ClickTrail\Symfony\Messenger\DeliverEventsMessage: async
```

handler 会刷新 SDK 的 `BatchClient`。容器必须提供 PSR-18 的 client/request/stream 工厂（例如 `symfony/http-client`）。除非显式配置，投递从不在请求期间发生。

## 同意管理

ClickTrail 是同意数据的消费方，不是 CMP。将 `resolver_class` 配置为实现 `ConsentResolverInterface` 的 FQCN，或用你自己的 CMP 适配器覆盖该别名。在此之前，内置的 `NullConsentResolver` 返回未知快照，处处视为拒绝：不持久化任何标识符，也不投递任何事件。

## 诊断

```bash
php bin/console clicktrail:diagnose
```

打印生效配置（密钥打码）并执行一次本地签名自测。

## Webhook 签名

使用 SHA-256 常量时间比较来验证 ClickTrail 的 webhook 回调：

```php
\ClickTrail\Symfony\Support\WebhookSignature::verify($payload, $signatureHeader, $secret);
// 仅当签名匹配时 === true；常量时间比较，无时序泄露
```

## Flex recipe 计划

为 `symfony/recipes-contrib` 提交默认 `config/packages/clicktrail.yaml` 骨架的 pull request 计划在**发版之后**——包没有打标签版本前无法提交 recipe。在那之前，请按上文所示手动创建配置文件。

## 刻意未包含的内容

**Doctrine 集成**（将归因快照持久化到实体、doctrine 事件监听器）被刻意排除在本包之外。计划作为后续可选包发布，让不使用 ORM 的应用保持零额外依赖的安装。

## 差异对比

- **自制的 UTM 转 Cookie 片段**把 URL 里带来的东西不加校验地存下来。ClickTrail 应用确定性的 first/last-touch 合并法则（经与 WordPress 和 GTM 引擎共享的金标准夹具验证）、以同意门控持久化，并以幂等键批量投递事件。
- **DirectoryTree/Metrics** 统计匿名事件。互补关系——ClickTrail 将广告系列与身份和营收关联起来。

完整分析见 `../docs/COMPETITOR-NOTES.md`。

## 测试

```bash
php tests/_runner.php                 # 完整套件，独立运行（无需启动内核）
```

CI 在 PHP 8.1–8.3 上对所有 PHP 文件做 lint（`.github/workflows/ci.yml`，规范模板来自 `../templates/ci-php-matrix.yml`）。

## 许可协议

MIT — 见 [LICENSE](LICENSE)。
