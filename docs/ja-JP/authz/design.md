---
sidebar_label: 認可の設計方針
description: Switon authz：ハンドラ近傍の規則、状態とビジネス権限の分離、単一ランタイム経路。
---

# 設計の狙い {#design-rationale}

`switon/authz` は、アクセス規則を HTTP ハンドラのそばに置き、**ログイン状態のマーカー**と**ビジネスロール**を分け、ランタイムでは **権限コードを厳密に** 評価することを目指します。

## 参照 {#see-also}

- [認可パッケージ](README.md) — 使い方・設定・注意点
- [FAQ](faq.md) — よくある質問とトラブルシュート

---

## コントローラ優先 {#controller-first}

ほとんどの規則は、その振る舞いを持つコントローラ／メソッド上にあるとレビューしやすくなります。

- **クラス既定:** コントローラの `#[Authorize]` がベースラインです。
- **メソッド上書き:** メソッド側の属性が、そのメソッドだけクラス既定を置き換えます。

## ユーザー状態とロール {#user-states-and-roles}

- **Identity の状態:** `Authorize::ANONYMOUS` と `Authorize::AUTHENTICATED` でゲストと認証済みを区別します。
- **バイパスロール:** `Authorize::SUPERUSER` は組み込みのグローバルバイパスキーです。
- **アプリケーションロール:** カスタム文字列はアプリ側のロールであり、`LookupInterface` で権限コードへ写します。

公開／認証済みの流れと、業務権限を同じ文字列群で混ぜないためにこの分割があります。

## 単一のランタイム経路 {#one-runtime-path}

`AuthorizationInterface` は、コントローラのゲートとサービス内チェックに **同じ API** を使います。

- **権限コード:** 通常の文字列は権限コード経路です。
- **ハンドラ参照:** 実在する `FQCN::method` 文字列はハンドラ参照経路です。
- **can():** 分岐用に真偽値だけ返します。
- **authorize():** HTTP 例外と `AuthorizationGranted` / `AuthorizationDenied` を伴います。

メタデータが必要なときも、ランタイムの約束事は小さく保てます。

## ロール→権限コード {#permission-lookup}

- **形式:** `LookupInterface` はカンマ区切りで高速かつ完全一致しやすくしています。
- **トレードオフ:** DB の行や設定文字列など、ソース側で値を正規化する必要があります。

## カタログと強制の分離 {#catalogs-stay-separate-from-enforcement}

- **カタログ・RBAC UI:** スキャンやエクスポート、管理画面向けは `PermissionsInterface` を使います。
- **強制イベント:** `authorize()` に沿って `AuthorizationGranted` / `AuthorizationDenied` が発火します。

ランタイムの可否判定と、一覧・運用向けのデータ経路を混ぜないようにしています。
