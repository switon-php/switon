---
sidebar_label: Command パッケージ
description: switon/command：コマンド発見、メタデータ、Hidden、Tool、ShortOption。
---

# Command パッケージ {#command-package}

`switon/command` は Switon のコマンドクラス向け **共有の発見とメタデータ層** を提供します。CLI はコマンド名・help・可視性・短いオプション別名のために読み、Beacon は AI 向けツール要約のために読みます。

**主な機能:**

- **共有コマンド発見:** `CommandDiscoveryInterface` がコマンド名→クラスのマップをキャッシュ付きで返す。
- **読みやすいメタデータ:** `CommandInspectorInterface` が反射と PHPDoc から要約・アクション要約・パラメータ help を読む。
- **可視性:** `#[Hidden]` で既定の一覧からコマンドやアクションを隠せる。
- **ツール説明:** `#[Tool]` でアクションに短い AI 向け説明を付けられる。
- **明示的な短い別名:** 既定では足りないとき `#[ShortOption]` で1文字エイリアスを固定する。

---

## インストール {#installation}

**要件:** PHP 8.3 以上

```bash
composer require switon/command
```

---

## クイックスタート {#quick-start}

普通のコマンドクラスを書き、CLI や Beacon が必要とするところにメタデータを足します。

```php
<?php

declare(strict_types=1);

namespace App\Command;

use Switon\Command\Attribute\Hidden;
use Switon\Command\Attribute\ShortOption;
use Switon\Command\Attribute\Tool;

/**
 * Report commands.
 */
final class ReportCommand
{
    /**
     * Show one report summary.
     *
     * @param string $format Output format.
     * @param bool $force Rebuild even when the current result looks fresh.
     */
    #[Tool('Returns one report summary in the requested format.')]
    public function statusAction(string $format = 'json', #[ShortOption('F')] bool $force = false): array {
        return [
            'format' => $format,
            'force' => $force,
        ];
    }

    #[Hidden]
    public function rebuildAction(): void
    {
    }
}
```

- `format` と `force` はどちらも `f` で始まる。
- `#[ShortOption('F')]` で `force` に `-F` を確保し、別名の衝突を避ける。

---

## コア概念 {#core-concepts}

### コマンドの形 {#command-shape}

- `*Command` クラスひとつがひとつのコマンド族。
- public な `*Action()` メソッドが実行可能なアクションになる。
- `defaultAction()` で素の `command` 入口を残す。

### 発見 {#discovery}

- アプリのコマンドは `App\Command` と `App\Areas\<Area>\Command` からスキャンされる。
- パッケージのコマンドは `composer.json` の `extra.switon.commands` から来る。
- `CommandDiscoveryInterface::discover()` は **キャッシュされた名前マップだけ** を返す。
- コマンドを登録したり実行したりはしない。

### メタデータ {#metadata}

- クラス／アクションの docblock が人向け要約になる。
- `#[Tool]` が AI ツール向けの短い synopsis を足す。
- `#[Hidden]` が既定の可視性を制御する（実行はブロックしない）。

### パラメータ {#parameters}

- アクション引数がオプション形を持ち、パラメータ名から長い／短いオプションが推論される。
- `@param` の文が help テキストになる。
- 既定の頭文字エイリアスが衝突するとき `#[ShortOption]` で短い別名を指定する。

### 別名 {#aliases}

- 既定の頭文字エイリアスが衝突するとき、長い／短い別名を明示的に保つ。

---

## 注意点 {#keep-in-mind}

- `#[Tool]` は短く。AI向け synopsis であって完全ガイドではない。
- `#[Hidden]` は既定の一覧から隠すだけで、実行から隠さない。
- コマンドが発見されないときはクラス名、次にスキャンパス、次に `extra.switon.commands` を確認する。

## 次のステップ {#next}

- **コンソールからコマンドクラスを走らせる:** [CLI パッケージ](/ja-JP/cli/)
- **コマンドアクションを AI ツールとして出す:** [Beacon パッケージ](/ja-JP/beacon/)
- **発見とメタデータのFAQ:** [FAQ](faq.md)
- **なぜこの層が別にあるか:** [設計の狙い](design.md)
