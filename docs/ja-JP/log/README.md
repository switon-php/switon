---
sidebar_label: Log パッケージ
description: switon/log：PSR-3、カテゴリ、levels、構造化 context、appender。
---

# Log パッケージ

`switon/log` は Switon アプリ向けに、自動カテゴリ・構造化 context・カテゴリ別レベルフィルタ付きの **単一 PSR-3 ロガー** を提供します。HTTP・CLI・ワーカー・テストで **ひとつ** の安定したログ契約が要るときに選ぶ。既定モデルにハンドラツリーや宛先ごとのルーティング規則は含まない。

**主な機能:**

- **標準ロガー契約:** `Psr\Log\LoggerInterface` を注入し、通常の PSR-3 メソッドを呼ぶ
- **自動カテゴリ:** 既定は **呼び出しサイト** から導出；`Switon\Core\Categorizable` で上書き可能
- **構造化 context:** `{key}` プレースホルダがメッセージに展開；それ以外は `extra` に残る
- **カテゴリ別フィルタ:** グローバルレベルと `levels` 上書き；最長プレフィックス一致でカテゴリプレフィックスにスコープ
- **出力 appender:** ファイル・stdout・syslog・メモリの組み込み appender

## インストール {#installation}

**要件:** PHP 8.3 以上

```bash
composer require switon/log
```

既定では `Psr\Log\LoggerInterface` → `Switon\Logging\Logger`。既定 binding は `LOG_LEVEL` を読み、なければ `debug` にフォールバックし、受理エントリをファイル appender へ。

## クイックスタート {#quick-start}

`LoggerInterface` を注入し、通常の PSR-3 メソッドを呼ぶ。

```php
namespace App\Service;

use Psr\Log\LoggerInterface;
use Switon\Core\Attribute\Autowired;

class UserService
{
    #[Autowired] protected LoggerInterface $logger;

    public function register(int $userId): void
    {
        $this->logger->info('User {user_id} registered', [
            'user_id' => $userId,
        ]);
    }
}
```

既定では受理エントリは `@runtime/logger/{scene}.log` へ。`{scene}` はフレームワークのシーンマネージャの **ランタイム scene**（例: `http`・`cli`・`schedule`）— リクエストごとではなく scene ごとに1ファイル。

## コア概念 {#core-concepts}

パイプライン順: **カテゴリとレベル** → **メッセージ形状** → **出力**。

### カテゴリ {#category}

**カテゴリ** は `levels` フィルタ用のドット区切り識別子。**ファイルパスでもチャンネルラベルでもない。**

既定ではロガーが **呼び出しサイト** から導出: クラスを持つ最初のスタックフレーム（クロージャはスキップ）+ メソッド名を安定したドット区切り id に。例: `App\Service\UserService::register()` → `app.service.user.register`。メソッド名が `Action` で終わる場合は先に除去（`registerAction` → `register`）。

上書き:

- `Switon\Core\Categorizable` を実装した `message` 引数で明示
- `context['exception']` があるとき、カテゴリは **スロー位置** から取り、`catch` ブロックのログ位置ではない

### ログフロー {#routing}

ログは単純な一本道:

`log()` → カテゴリ → レベルフィルタ → `LogEntry` → appender

- `LOG_LEVEL` は `levels` プレフィックスがカテゴリに一致しないときの既定レベル
- `levels` では一致する最長カテゴリ **プレフィックス** がその呼び出しの **しきい値** を設定。しきい値より **詳細** なレベルは捨てる（例: 有効しきい値が `info` のときの `debug`）
- `LoggerLogged` リスナーは appender 前に `LogEntry::extra` へフィールド追加可能；[FAQ](faq.md#logger-logged-boundaries) を参照

### メッセージと context {#message}

**パターン:** 人が読む文はメッセージ。呼び出しごとのフィールドは `context`。

- メッセージ単体で読めるようにする
- `{key}` は文に属する値だけ
- `{key}` に一致するキーはメッセージに補間
- プレースホルダに一致しないキーは `extra` の構造化データ
- `context['exception']` は throwable をレンダリングし、Switon 例外 context を載せうる

```php
try {
    $this->createOrder($items);
} catch (\Throwable $e) {
    $this->logger->error('Order creation failed', ['exception' => $e]);
}
```

ロガーは `LogEntry` を保持: 補間キーは `message`、残り context は `extra`、`exception` は `extra` ではなく `message` にレンダリング。`Switon\Core\Exception` では `getContext()` が `extra` にマージ（衝突時は呼び出し側キーが勝つ）。

### 出力 {#output}

受理エントリは設定順に **すべて** の appender へ。

- 組み込み: ファイル・stdout・syslog・メモリ
- appender は独立宛先だがロガーは順次呼び出し；スローする appender は後続を止める
- 組み込みファイル appender は書き込み失敗を別チャンネルで報告し、リクエストは失敗させない（[FAQ](faq.md#file-write-failure)）
- `FormatterInterface` がテキストまたは JSON 出力を制御

### 設定 {#configuration}

まず宛先ひとつとグローバルレベル。アプリの一部だけ多すぎ／少なすぎるときだけカテゴリ上書きを足す。

`switon.yml` で設定:

```yaml
# switon.yml
Psr\Log\LoggerInterface:
  level: info
  levels:
    app.service.payment: warning
```

### オプションの確認 {#option-discovery}

```bash
bash bin/console config:options 'Psr\Log\LoggerInterface'
```

### 注意点 {#keep-in-mind}

- ログが出ないときはまず宛先と有効レベル
- 一部だけ出ないときは `levels` の最長一致プレフィックス（logger binding の既定も含め `config:options` やマージ済み設定を確認、`switon.yml` だけに限定しない）
- リクエストメタデータや相関 id は各呼び出しで繰り返さず `LoggerLogged` リスナーに

## 次のステップ {#next}

- **挙動と既定:** [FAQ](faq.md)
- **トレードオフ:** [設計の狙い](design.md)
