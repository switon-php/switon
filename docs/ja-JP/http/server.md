---
sidebar_label: Server
description: ServerOptions、Kernel::start()、FPM・PHP 組み込み・Swoole。
---

# サーバーとデプロイ {#server-deployment}

FPM・PHP 組み込みサーバー・Swoole 向けに HTTP ランタイムとサーバーオプションを選ぶ。

**主な機能:**

- **ランタイム選択** — `ServerOptions::type` で `auto`・`php`・`fpm`・`swoole`
- **起動入口** — `Kernel::start()` でアプリ起動後に HTTP サーバー開始
- **アダプタ設定** — ホスト・ポート・アダプタ固有設定は `ServerOptions`
- **サーバーイベント** — 起動・シャットダウン・ワーカーのライフサイクル

## クイックスタート {#quick-start}

`switon.yml` でサーバーオプションを設定。

```yaml
# switon.yml
Switon\Http\ServerOptions:
  type: auto
  host: 0.0.0.0
  port: 9501
```

既定は `type: auto`。ランタイムを固定するときだけ `php`・`fpm`・`swoole`。

## コア概念 {#core-concepts}

### ランタイム選択 {#runtime-selection}

`ServerOptions` がアダプタと待受アドレスを制御。

- `host` — 待受ホスト
- `port` — 待受ポート
- `type` — ランタイムアダプタ
- `settings` — アダプタ固有オプション

### 起動 {#startup-and-configuration}

`Kernel::start()` は先にアプリを起動し、その後サーバーを開始。

### サーバーイベント {#server-events}

起動・シャットダウン・ワーカーライフサイクル用:

- `Switon\Http\Server\Event\ServerReady`
- `Switon\Http\Server\Event\ServerStart`
- `Switon\Http\Server\Event\ServerStarted`
- `Switon\Http\Server\Event\ServerWorkerStart`
- `Switon\Http\Server\Event\ServerShutdown`

イベントの dispatch モデルは [Event パッケージ](/ja-JP/event/) を参照。

### Nginx {#nginx}

Swoole を Web サーバー境界の背後で動かすときは Nginx をリバースプロキシに。

## 注意点 {#keep-in-mind}

- 最初は `type: auto` が安全。
- ランタイム固有のチューニングは `ServerOptions.settings` に。
- 通常のリクエスト処理は `RequestHandler` に置き、サーバー起動コードに混ぜない。

## 次のステップ {#next}

- **レスポンス:** [Response](response.md)
- **リクエスト入力:** [Request](request.md)
- **オプション確認:** [FAQ](faq.md)
