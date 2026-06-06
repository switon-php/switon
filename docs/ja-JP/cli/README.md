---
sidebar_label: CLI パッケージ
description: Switon CLI：*Command クラス、オプション束縛、help、ターミナルユーティリティ。
---

# CLI パッケージ {#cli-package}

`switon/cli` は Switon アプリに、素直なコマンドクラス・オプション束縛・help 出力・ターミナル向けユーティリティをまとめた **ひとつのコマンド面** を与えます。

**主な機能:**

- **素直なコマンドクラス:** 小さなコマンド群はひとつの `*Command` クラスが所有
- **シンプルな形:** `command` または `command:action` で実行
- **自動パラメータ:** 名前付きオプションが先、残りを位置引数が埋める
- **組み込みの発見と help:** 生成カタログなしで `list`、`help`、`--help` が動く
- **ターミナル向けユーティリティ:** `ConsoleInterface` と `Switon\Console\ProgressBarInterface` を注入して出力・プロンプト・プログレス
- **隠しメタデータ:** `#[Hidden]` で既定の `list` からサポート用コマンドを外せる
- **AI／ツール向けメタデータ:** `#[Tool]` でエージェント向けの安定した説明を付けられる

---

## インストール {#installation}

**要件:** PHP 8.3 以上

Composer で:

```bash
composer require switon/cli
```

---

## クイックスタート {#quick-start}

多くのアプリはスケルトン（または自前レイアウト）が **`bin/console`** を用意していればそれ経由で動かします。

### フォールバック入口 {#run-entry}

`bin/console` が **無い** ときは、小さな PHP 入口（よく `switon.php`）から CLI カーネルを起動します。

```php
<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Switon\Cli\Kernel;

$kernel = new Kernel();
$kernel->start();
```

このスクリプトを `bin/console` の代わりに叩きます（例: `php switon.php list`）。

`bin/console` がある場合、多くは上記と **同じ** CLI カーネルを包んでいます。

```bash
bash bin/console list
```

- 機械可読なコマンド一覧が必要なら `bash bin/console list --json`。
- シェル補完を入れるなら `bash bin/console completion:install`。
- 入り方を確認するなら `bash bin/console completion:check`。

### コマンド例 {#example-command}

```php
<?php

declare(strict_types=1);

namespace App\Command;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;

/**
 * Inspect and rebuild report snapshots from the console.
 */
class ReportCommand
{
    #[Autowired] protected ConsoleInterface $console;

    /**
     * Show one daily report summary.
     *
     * @param string $date Report date in Y-m-d format.
     */
    public function dailyAction(string $date = '2026-05-03'): void
    {
        $this->console->success('Report ready for {date}.', ['date' => $date]);
    }

    /**
     * Rebuild one snapshot and print the result.
     *
     * @param int $id Snapshot id.
     * @param bool $force Rebuild even when snapshot looks fresh.
     */
    public function rebuildAction(int $id, bool $force = false): void
    {
        $this->console->info('Rebuilt snapshot {id}.', ['id' => $id]);
    }
}
```

```bash
bash bin/console report:daily
bash bin/console report:daily --date=2026-05-03
bash bin/console report:rebuild --id=42 --force
bash bin/console report:daily --help
bash bin/console list
```

---

## コア概念 {#core-concepts}

CLI は次の四つの規則で予測しやすくなります。

- **形:** `*Command` クラスひとつがひとつのコマンド族。
- **ルーティング:** public な `*Action()` メソッドが実行エントリ。
- **束縛:** 名前付きオプションが先、あとは位置引数。
- **表示:** 隠しコマンドも実行はできるが、既定の人向け `list` には出ない。

### 形 {#shape}

`*Command` クラスひとつがひとつのコマンド族です。

- コマンド名はクラス名から:
  - `ReportCommand` → `report`
  - `BackupDatabaseCommand` → `backup-database`
  - `App\Areas\Rbac\Command\UserRoleCommand` → `user-role`

### ルーティング {#routing}

公開契約は `command` または `command:action` です。

- アクション名はメソッド名から:
  - `defaultAction()` → 素の `command`（例: `greet`）
  - `createAction()` → `command:create`
  - `createBackupAction()` → `command:create-backup`

### 発見 {#discovery}

アプリのコマンドは `App\Command` と `App\Areas\<Area>\Command` から。
パッケージのコマンドは `composer.json` の `extra.switon.commands` に登録し、`switon/composer-extra` 経由で読みます。

- スキャンしたパスとパッケージメタデータからクラスを見つけます。

### 可視性 {#visibility}

発見されても、既定の人向け `list` に出すかは別です。

支援用コマンドやアクションを既定の `list` から外したいときは `#[Hidden]`:

```php
use Switon\Command\Attribute\Hidden;

class ReportCommand
{
    #[Hidden]
    public function rebuildIndexAction(): void
    {
    }
}
```

- 隠しエントリも、呼び出し方が分かれば実行できる。
- `bash bin/console list --all` で含める。

### パラメータ束縛 {#parameter-binding}

アクション引数はまず名前付きオプション、残りを宣言順の位置引数で埋めます。

```php
public function createAction(string $email, string $name, string $role = 'user'): void
{
}
```

```bash
bash bin/console user:create --email=john@example.com --name=John
bash bin/console user:create john@example.com John
bash bin/console user:create --role=admin john@example.com John
```

- 頭文字エイリアスが衝突しそうなら `#[ShortOption]` を使う。

### 注意点 {#keep-in-mind}

- `defaultAction()` で素の `command` エントリを残せる。
- `list` に無いと思ったら `list --all` を試す。

---

## 次のステップ {#next}

- **定期実行の前にコンソールへサービスを配線:** [CLI を追加する](/en/guides/add-cli)
- **動いたコマンドやサービスを定期ワークに:** [定期ワークを追加する](/en/guides/add-recurring-work)
- **起動・env・設定ブートストラップ:** [Kernel パッケージ](/en/kernel/)
- **cron 風や長寿命スケジュール実行:** [Schedule パッケージ](/en/schedule/)
- **インライン実行ではなくランナーで整える:** [Executor パッケージ](/en/executor/)
- **FAQ:** [FAQ](faq.md)
- **形・発見・可視性・ツールメタの設計:** [設計の狙い](design.md)
