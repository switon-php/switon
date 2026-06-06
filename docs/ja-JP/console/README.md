---
sidebar_label: Console パッケージ
description: switon/console：プログレスバー、端末幅、ANSI 色フラグ。
---

# Console パッケージ {#console-package}

`switon/console` は Switon アプリ向けに、プログレスバー・端末幅の取得・ANSI 色フラグといった **再利用可能なターミナル原語** を提供します。

**主な機能:**

- **プログレスバー:** `ProgressBar` が自動開始し、進捗をクランプし、経過・残り時間を描画する。
- **幅検出:** `TerminalWidthDetector` が現在の端末幅をキャッシュし、Unix／Windows それぞれのフォールバック経路を持つ。
- **ANSI スタイル:** `Colors` が前景・背景・テキスト属性のビットマスクをまとめる。
- **フレームワーク向け契約:** `ProgressBarInterface` と `TerminalWidthDetectorInterface` は小さく配線しやすい。

---

## インストール {#installation}

**要件:** PHP 8.3 以上

```bash
composer require switon/console
```

---

## クイックスタート {#quick-start}

- 進捗出力には `ProgressBarInterface` を注入する。
- スタイル付きテキストには `Colors` と `Switon\Core\ConsoleInterface::colorize()` を使う。

```php
use Switon\Console\Colors;
use Switon\Console\ProgressBarInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;

class ImportUsers
{
    #[Autowired] protected ProgressBarInterface $progressBar;
    #[Autowired] protected ConsoleInterface $console;

    public function run(array $rows): void
    {
        $this->progressBar->start(count($rows));

        foreach ($rows as $row) {
            $this->importRow($row);
            $this->progressBar->advance();
        }

        $this->progressBar->finish();
        $this->console->writeLn(
            $this->console->colorize('Import complete', Colors::FC_LIGHT_GREEN | Colors::AT_BOLD)
        );
    }
}
```

---

## コア概念 {#core-concepts}

### プログレスバー {#progress-bars}

`ProgressBar` は **ひとつのターミナルタスク** 向けの、その場更新の出力原語です。

- `start()` で描画を始め、任意で最大値を渡せる。
- `advance()`、`setProgress()`、`finish()` は必要ならバーを自動で開始する。
- `finish()` は最後に改行を付ける。
- フォーマット文字列に `%current%`、`%max%`、`%percent%`、`%bar%`、`%elapsed%`、`%remaining%`、`%rate%` が使える。
- `setMessage()` で描画行に短い注釈を足せる。

### 端末幅 {#terminal-width}

`TerminalWidthDetector` は **キャッシュされた幅** を返し、`clearCache()` の後だけ更新する。

- まず `COLUMNS` を見る。
- Unix では `stty`、次に `tput` にフォールバック。
- Windows では `mode con`、次に PowerShell にフォールバック。
- 極端に小さい値は最小サポート幅 `20` まで持ち上げる。

### ANSI 色 {#ansi-colors}

`Colors` は `Switon\Core\ConsoleInterface::colorize()` 向けに、前景・背景・属性のフラグをまとめる。

- フラグは `|` で合成する。
- `Switon\Cli\Console` のような `ConsoleInterface` 実装と組み合わせる。

---

## 注意点 {#keep-in-mind}

- `ProgressBar` は状態を持ち、**ひとつのアクティブなターミナルフロー** 向け。
- `TerminalWidthDetector` はキャッシュするので、テストやリサイズに敏感なコードでは `clearCache()` が必要かもしれない。
- `Colors` はビットマスクであり、名前付きテーマではない。

---

## 次のステップ {#next}

- **コマンドで出力・プロンプト・色付け:** [CLI パッケージ](/ja-JP/cli/)
- **よくある質問:** [FAQ](faq.md)
- **設計トレードオフ:** [設計の狙い](/ja-JP/console/design)
