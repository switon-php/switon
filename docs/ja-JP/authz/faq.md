---
sidebar_label: 認可 FAQ
description: Switon authz：ゲスト、`#[Authorize]`、can と authorize、Lookup、Voter、トラブルシュート。
---

# 認可 FAQ {#authorization-faq}

よくある認可まわりの短い回答です。

## はじめに {#getting-started}

### ゲストにも公開したい {#guest-access}

`#[Authorize(Authorize::ANONYMOUS)]` を使います。

### 空の `#[Authorize]` の意味は？ {#empty-authorize}

**自動では公開になりません。** 公開メソッドには `Authorize::ANONYMOUS` を明示してください。空の `#[Authorize]` でもメソッドは認可フローに残ります。**認証済み**ユーザーは Lookup まで進みます。**ゲスト**は Lookup の前に拒否されます。

### `can()` と `authorize()` はどちらを使う？ {#can-vs-authorize}

分岐だけなら `can()`、HTTP の境界（とイベント）まで必要なら `authorize()` です。

### ロールはどこから来る？ {#roles-source}

`Switon\Principal\IdentityInterface::getRoles()` です。認証処理は Identity 側にあります。

## 設定 {#configuration}

### カスタムロールに権限を付けるには？ {#custom-role-permissions}

`LookupInterface` で、**スペースなし**のカンマ区切りで権限コードを正確に対応付けます。

### Voter を登録するには？ {#register-voters}

`switon.yml` で `Switon\Authorizing\AuthorizationInterface` に `voters` リストを書きます。

### カタログや RBAC 画面は？ {#catalog-ui}

`PermissionsInterface` を使います。

## トラブルシュート {#troubleshooting}

### カスタムロールがいつも拒否される {#custom-role-denied}

権限コードの綴りと、`LookupInterface` のカンマ区切りに **スペースが混ざっていないか** 確認してください。

### ハンドラ参照が拒否される {#handler-reference-denied}

実在する `FQCN::method` で **`\` と `::` の両方** があるときだけハンドラ参照として扱われます。メソッドが無ければすぐ拒否されます。`admin::index` のように **バックスラッシュが無い**文字列は権限コード側のままです。

### `401` と `403` の違いは？ {#401-vs-403}

解決後のロール集合が **空**なら `UnauthorizedException`（401）、**空でない**のに拒否なら `ForbiddenException`（403）です。

### Voter は `Authorize::SUPERUSER` を止められる？ {#voter-block-superuser}

はい。Voter は組み込みのロール許可より **前** に動くので、`false` なら Identity に `Authorize::SUPERUSER` があっても拒否できます。

### いつ「ハンドラ参照」になる？ {#handler-reference-rules}

実在メソッドへの **本物の** `FQCN::method`（`\` と `::` を含む）のときだけです。それ以外はすべて権限コードとして扱われます。

### `AuthorizationGranted` / `AuthorizationDenied` はいつ？ {#authz-events}

`authorize()` を通ったときだけです。`can()` では発火しません。
