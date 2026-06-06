---
sidebar_label: Event パッケージ
description: switon/event：PSR-14、#[EventListener]、同期 dispatch、EventLogger。
---

# Event パッケージ {#event-package}

`switon/event` は Switon アプリ向けに、属性リスナー・ワイルドカードリスナー・自動イベントログ付きの **クラスベースのプロセス内 dispatch** を提供します。

**主な機能:**

- **PSR-14 入口:** `Psr\EventDispatcher\EventDispatcherInterface` を注入して `dispatch()`。
- **クラスベースのイベント:** クラス名がイベントの identity、オブジェクトがペイロード。
- **属性による発見:** `#[EventListener]` がスキャンパスまたは Composer メタデータから public リスナーメソッドを登録。
- **ワイルドカード:** `object` 型パラメータで全イベントを受け取る。
- **優先度:** 数値が小さいほど先に実行。
- **組み込みイベントログ:** 既定は `debug` で、業務リスナーと分離可能。
- **可観測性の分離:** `ObservabilityProbe` が監視系リスナーを業務カウントから外す。

---

## インストール {#installation}

**要件:** PHP 8.3 以上

```bash
composer require switon/event
```

既定では `Psr\EventDispatcher\EventDispatcherInterface` → `Switon\Eventing\EventDispatcherInterface`、`Psr\EventDispatcher\ListenerProviderInterface` → `Switon\Eventing\ListenerProviderInterface` に解決されます。

---

## クイックスタート {#quick-start}

イベントをひとつ定義し、dispatch し、リスナーで処理する。

```php
namespace App\Event;

final class UserCreated
{
    public function __construct(
        public readonly int $userId,
        public readonly string $email,
    ) {
    }
}
```

```php
namespace App\Service;

use App\Event\UserCreated;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\Attribute\Autowired;

class UserService
{
    #[Autowired] protected EventDispatcherInterface $events;

    public function register(string $email): void
    {
        $this->events->dispatch(new UserCreated(123, $email));
    }
}
```

```php
namespace App\Listener;

use App\Event\UserCreated;
use Switon\Eventing\Attribute\EventListener;

class UserListener
{
    #[EventListener]
    public function onUserCreated(UserCreated $event): void
    {
        // react here
    }
}
```

既定では `app/Listener/*Listener.php` と `app/Areas/*/Listener/*Listener.php` 下のリスナークラスが発見されます。
リスナーメソッドは **型付きパラメータを1つ** 宣言する。
具体イベントクラス、`object`、または union 型を使う。
型なしや intersection 型のメソッドは登録時に fail-fast する。

`dispatch()` は **同期** で、同じイベントオブジェクトを返す。

---

## 用語 {#terms}

- `event class` — イベント名とペイロードを担う PHP クラス
- `listener` — `#[EventListener]` 付き public メソッドで、型付きパラメータがちょうど1つ
- `wildcard listener` — パラメータ型が `object` のリスナー
- `observability listener` — `ObservabilityProbe` を実装するリスナークラス
- `StopFlow` — 残りのリスナーを意図的に止める
- `EventLogger` — dispatch されたイベントをログできる組み込みワイルドカードリスナー

---

## コア概念 {#core-concepts}

### イベント {#events}

- イベントは素の PHP クラス。
- クラス名が identity、オブジェクトがペイロード。

- ペイロードは明示的で安定させる。
- 大きな可変オブジェクトや生の機密データより、ID・enum・短い要約を優先。

### リスナー {#listeners}

`#[EventListener]` 付きの public メソッド。

- 型付きパラメータは **ちょうど1つ**。
- 具体クラスで単一イベント、`object` で全イベント、union で複数クラス。
- `#[EventListener(priority: N)]` — 小さい数が先。同じ優先度は登録順。

- マッチは **具体イベントクラス** のみ（親クラスは拾わない）。

### 作成と dispatch {#create-and-dispatch}

- dispatch は現在のプロセス内。
- ワイルドカードリスナーが先、イベント固有リスナーが後。
- `dispatch()` は同じイベントオブジェクトを返す。

- 残りのリスナーを止めたいときは `Switon\Core\StopFlow` を投げる。

- リスナープロバイダが配線されていなければ `dispatch()` は no-op で元のイベントを返すだけ。

### イベントログ {#event-logging}

- `EventLogger` が dispatch を自動ログできる。
- 特定イベントをスキップ: `EventSilent`。
- 既定 `debug` 以外の重大度: `#[EventLevel]`。
- カスタムログ: `EventLogInterface`。

- 主にログ・トレース・メトリクスのリスナーは `ObservabilityProbe` を実装し、業務リスナー数から分離。

- `EventWrapper` は既定で public スカラーペイロードをシリアライズ。オブジェクト値は `jsonSerialize()` がないとスキップ。

---

## 設定 {#configuration}

- リスナー発見は設定されたスキャンパス + `extra.switon.listeners` の Composer メタデータ。
- 既定アプリパス: `@app/Listener/*Listener.php`、`@app/Areas/*/Listener/*Listener.php`。

- 自動イベントログは既定で有効。
- `Switon\Eventing\EventLoggerInterface` で `enabled: false` にすると dispatch 自体は止めない。

---

## オプションの確認 {#option-discovery}

```bash
bash bin/console config:options 'Switon\Eventing\ListenerDiscoveryInterface'
bash bin/console config:options 'Switon\Eventing\EventLoggerInterface'
```

---

## 注意点 {#keep-in-mind}

- `dispatch()` を非同期処理とみなさない。
- リスナーの戻り値は期待しない。
- リスナーは `public`、`#[EventListener]`、パラメータ1つ、発見パス上のクラスが必要。

---

## 次のステップ {#next}

- **登録とセットアップ:** [FAQ](faq.md)
- **同期 dispatch と可観測性:** [設計の狙い](design.md)
- **ログ方針との接続:** [Log パッケージ](/en/log/)
