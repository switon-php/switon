---
sidebar_label: Composer Extra パッケージ
description: switon/composer-extra：`extra` メタのランタイムキャッシュ、collect、Composer フック。
---

# Composer Extra パッケージ {#composer-extra-package}

`switon/composer-extra` は、インストール済みパッケージの `extra` メタデータを **ひとつのキャッシュファイル** にまとめ、ランタイムが毎リクエスト Composer 内部を触らずに読めるようにします。

**主な機能:**

- **ランタイムリーダー:** `ComposerExtraInterface` が生成済みキャッシュからパッケージメタを読む。
- **発見用リスト:** `collect()` が `extra.switon.*` のリストを**順序付き**の文字列として読む。
- **Composer 更新フック:** `post-install-cmd` と `post-update-cmd` でキャッシュを再構築。
- **ローカルオーバーレイ:** path インストールのパッケージは開発中に現在の `composer.json` メタが反映されうる。
- **ベストエフォート更新:** キャッシュ書き込み失敗は報告するが Composer 自体は中断しない。

---

## インストール {#installation}

**要件:** PHP 8.3 以上、`ext-json`、Composer 2.0+、`composer-plugin-api` ^2.0

```bash
composer require switon/composer-extra
```

Composer 2.2+ ではルートプロジェクトでプラグインの許可リストが追加で必要です。

---

## クイックスタート {#quick-start}

- まずルートの `composer.json` でプラグインを許可する。
- 依存関係をインストールする。
- ランタイムでパッケージメタが要る箇所に `ComposerExtraInterface` を注入する。
- `allow-plugins` の具体的な JSON は [設定](#configuration) を参照。

```php
namespace App\Service;

use Switon\ComposerExtra\ComposerExtraInterface;
use Switon\Core\Attribute\Autowired;

final class PackageRegistry
{
    #[Autowired] protected ComposerExtraInterface $composerExtra;

    public function providers(): array
    {
        return $this->composerExtra->collect('switon.providers');
    }

    public function cacheHealth(): array
    {
        return $this->composerExtra->health();
    }
}
```

---

## コア概念 {#core-concepts}

- このパッケージの仕事はひとつ: Composer 変更後のパッケージ `extra` を取り込むこと。
- ランタイム向けに **安定したキャッシュひとつ** を公開する。

### キャッシュの流れ {#cache-flow}

- `post-install-cmd` と `post-update-cmd` がプラグインを起動する。
- プラグインは Composer メタからインストール済みパッケージの `extra` を読む。
- path インストールのパッケージは生の `composer.json` にフォールバックしうる。
- プラグインが `vendor/switon/composer-extra.json` を書く。
- `Switon\ComposerExtra\ComposerExtra` がランタイムでそのファイルを読む。

### パッケージメタデータ {#package-metadata}

`Switon\ComposerExtra\ComposerExtraInterface` がランタイムにパッケージマップを渡す。

- `get(string $packageName): array` — そのパッケージの `extra`、無ければ `[]`。
- `all(): array` — パッケージマップ全体。
- `has(string $packageName): bool` — `extra` が空でないか。
- `health(): array` — `ok`、`reason`、`cacheFile`、`packageCount` など。

### 発見用リスト {#discovery-lists}

`extra.switon.*` 直下の発見スタイルのメタには `collect(string $path)` を使う。

- パスはドット区切り。
- 空のセグメントは無視。
- 葉は文字列ひとつか文字列の配列。
- 値は**パッケージ順**で連結される。

```php
$providers = $composerExtra->collect('switon.providers');
$listeners = $composerExtra->collect('switon.listeners');
```

---

## 設定 {#configuration}

このパッケージ用の `switon.yml` ブロックは無い。多くのプロジェクトで必要なのは Composer プラグインの許可だけ。
ルート `composer.json` の `config` に次を足す:

```json
{
  "config": {
    "allow-plugins": {
      "switon/composer-extra": true
    }
  }
}
```

---

## 注意点 {#keep-in-mind}

- `vendor/switon/composer-extra.json` を手で編集しない。
- フレームワークの発見用メタは `collect()` を使う。
- インストール／更新や path オーバーレイでおかしいときは、まず `health()` でキャッシュを確認する。

---

## 次のステップ {#next}

- **キャッシュ失敗とエッジケース:** [FAQ](/ja-JP/composer-extra/faq)
- **設計上のトレードオフ:** [設計の狙い](/ja-JP/composer-extra/design)
