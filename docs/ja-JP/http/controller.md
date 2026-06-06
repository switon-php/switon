---
sidebar_label: Controller
description: 属性ルーティング、アクションパラメータ、型付き入力、認可とビュー。
---

# Controller {#controller}

ルート属性・アクションパラメータ・型付き入力・コントローラスコープの拡張点で HTTP エンドポイントを定義する。

**主な機能:**

- **属性ルーティング** — `#[RequestMapping]` がプレフィックス、`#[GetMapping]` などが動詞とアクション
- **アクションパラメータ** — ルート値・リクエスト入力・DI サービスを同一シグネチャに
- **型付き入力** — `#[RequestBody]` などで検証付きバインド
- **アクション単位の拡張** — ビュー・認可・インタセプタを対象アクション近くに
- **中央例外フロー** — ローカル `try/catch` ではなく HTTP 例外経路へ収束

## クイックスタート {#quick-start}

`app/Controller/` または `app/Areas/*/Controller/` にコントローラを置き、クラスに `#[RequestMapping]`、アクションに動詞属性。

```php
namespace App\Controller;

use Switon\Http\Controller;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('/user')]
class UserController extends Controller
{
    #[GetMapping('')]
    public function indexAction(int $page = 1): array
    {
        return ['page' => $page];
    }

    #[GetMapping('{id}')]
    public function showAction(int $id): array
    {
        return ['id' => $id];
    }

    #[PostMapping('')]
    public function createAction(string $name, string $email): array
    {
        return ['name' => $name, 'email' => $email];
    }
}
```

ルート発見は起動時。ルートが出ないときは `bash bin/console router:list`。

## コア概念 {#core-concepts}

### ルーティング {#routing}

クラスとアクションの属性からルートが決まる。

- `#[RequestMapping]` — プレフィックス
- `#[GetMapping]`・`#[PostMapping]`・`#[PutMapping]`・`#[PatchMapping]`・`#[DeleteMapping]` — 動詞
- `#[ViewGetMapping]`・`#[ViewPostMapping]`・`#[ViewMapping]` — ビュー向け

### アクションパラメータ {#action-parameters}

名前または型で解決。

- ルート値 — 名前でバインド
- スカラー — リクエスト入力から
- サービス — コンテナから

まず小さなシグネチャ。ヘッダ・ファイルなど生データが要るときだけ `RequestInterface`。

### 型付き入力 {#typed-input}

安定したリクエスト形状には型付き入力クラス。

- `#[Switon\Http\Attribute\RequestBody]` — 解析済みボディ
- `#[Switon\Http\Attribute\RequestQuery]` — クエリ
- `#[Switon\Http\Attribute\RequestData]` — マージ済み

ネストした型付きプロパティに属性は繰り返さない。バインド時に検証。

### 認可 {#authorization}

認可属性がアクション境界を守る。インタセプタは付いたアクションだけを包む。詳細は [認可パッケージ](/ja-JP/authz/) を参照。

### ビューレンダリング {#view-rendering}

HTML を返すときはビュー層。

- `#[ViewGetMapping]` — 通常 GET は HTML；JSON 希望クライアントではアクションも invoke
- `#[ViewMapping]` — GET は常に HTML；データ準備のためアクションは invoke しうる

`#[ViewGetMapping]` では JSON 優先リクエストがアクション実行をスキップしうる。`#[ViewMapping]` はスキップしない。

### 例外 {#exceptions}

アクション内の例外レンダリングは不要。

- フレームワーク例外は HTTP 例外ディスパッチャへ
- コントローラは通常の返却値に集中
- 既定の HTTP 応答方針で足りないときだけカスタムハンドラ

## 注意点 {#keep-in-mind}

- `#[GetMapping('')]` はクラスプレフィックスのみ。
- `#[GetMapping]` はアクション名をルートサフィックスに使う。
- JSON のよくある経路は配列返却。
- アクションパラメータで足りないときだけ `RequestInterface`。

## 次のステップ {#next}

- **入力とメタデータ:** [Request](request.md)
- **レスポンス:** [Response](response.md)
- **型付き入力の詳細:** [型付き入力バインド](typed-input-binding.md)
- **FAQ:** [FAQ](faq.md)
