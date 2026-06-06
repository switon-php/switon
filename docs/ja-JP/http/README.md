---
sidebar_label: HTTP パッケージ
description: switon/http：コントローラ、Request/Response、型付き入力、ライフサイクル、サーバーアダプタ。
---

# HTTP パッケージ {#http-package}

`switon/http` は、リクエスト入力・レスポンス整形・ライフサイクルイベント・ランタイムアダプタを備えた **コントローラベースの HTTP 境界** を Switon アプリに提供します。

**主な機能:**

- **コントローラアクション:** `#[RequestMapping]` と `switon/router` の HTTP 動詞属性でルートをメソッドにマップ
- **リクエスト境界:** `Switon\Http\RequestInterface` でルート・クエリ・ボディ・ヘッダ・ファイル・属性を読む
- **レスポンス境界:** `Switon\Http\ResponseInterface` でステータス・ヘッダ・Cookie・リダイレクト・ダウンロード・ストリーミング
- **型付き入力:** `#[RequestBody]`・`#[RequestQuery]`・`#[RequestData]` でボディ・クエリ・マージデータを型付き入力クラスにバインド
- **ライフサイクルフック:** リクエスト／レスポンスイベントに横断関心を寄せ、アクションに散らさない
- **フィルタ層:** リクエスト ID・ロケール・マッピング確認などリクエスト全体のフィルタ
- **ランタイムアダプタ:** 同じ HTTP コードを FPM・PHP 組み込みサーバー・Swoole で実行
- **CLI 確認:** `filter:list` で発見済みフィルタと `RequestHandler` で有効なものを表示

## インストール {#installation}

**要件:** PHP 8.3+、`ext-dom`；Swoole は Swoole ランタイムを使うときのみ任意

```bash
composer require switon/http
```

既定では `Switon\Core\InputInterface` → `Switon\Http\RequestInterface` にバインドされます。

---

## クイックスタート {#quick-start}

コントローラアクションをひとつ定義し、配列を返して JSON として正規化させる。

```php
namespace App\Controller;

use Switon\Http\Controller;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('/users')]
class UserController extends Controller
{
    #[GetMapping('')]
    public function indexAction(): array
    {
        return ['users' => ['Alice', 'Bob']];
    }

    #[GetMapping('{id}')]
    public function showAction(int $id): array
    {
        return ['id' => $id];
    }

    #[GetMapping('search')]
    public function searchAction(string $keyword, int $page = 1): array
    {
        return ['keyword' => $keyword, 'page' => $page];
    }
}
```

最初のルートにはこれで足りる。スカラーアクションパラメータは名前でバインドされ、`int $id`・`string $keyword`・`int $page` はルートまたはリクエスト入力から来る。生入力が要るときは `RequestInterface`、ステータス・ヘッダ・リダイレクト・ファイル・ストリーミングが要るときだけ `ResponseInterface`。

アプリに `/api` などのグローバルルータープレフィックスがある場合、公開 URL は `/api/users`・`/api/users/{id}` になる。

---

## コア概念 {#core-concepts}

### コントローラアクション {#controller-actions}

コントローラクラスがルート宣言、アクションメソッドが HTTP 入口。

- `#[RequestMapping]` — コントローラプレフィックス
- `#[GetMapping]`・`#[PostMapping]` など — 動詞とアクションの対応
- アクションパラメータ — ルート値と DI サービス

### リクエスト {#request}

`RequestInterface` はルート・クエリ・解析済みボディ・ヘッダ・ファイル・属性の読み取り境界。

### 型付き入力 {#typed-input}

安定したリクエスト形状が要るときは型付き入力クラスと検証を使う。

```php
use Switon\Http\Attribute\RequestBody;
use Switon\Validating\Attribute\Length;

#[RequestBody]
class CreateUserInput
{
    #[Length(1, 64)]
    public string $name;
}

public function createAction(CreateUserInput $input): array
{
    return ['name' => $input->name];
}
```

- `#[RequestBody]` — 解析済みボディ
- `#[RequestQuery]` — クエリ
- `#[RequestData]` — マージ済みリクエスト

### レスポンス {#response}

`ResponseInterface` はステータス・ヘッダ・Cookie・リダイレクト・ファイル・ボディの書き込み境界。
通常の JSON は配列を返す；明示制御が要るときだけ `ResponseInterface`。

### レスポンス確定 {#finalization}

レスポンス確定は、ルーティング・検証・invoke・レンダリングの後、HTTP フローの終端。

### ライフサイクルイベント {#lifecycle-events}

リクエストフローは拡張駆動。

- リクエスト段階: `adjust`・`begin`・`authenticate`・`route`・`authorize`・`validate`・`ready`・`invoke`・`render`・`end`（イベント識別子として英語名を維持）
- レスポンス段階: `adjust`・`stringify`・`headers`・`body`、およびサーバー出力前のアセット送信（同上）
- リスナーがリクエスト ID・ロケール・ログなどリクエスト全体の関心の主な置き場
- 認可・検証イベントは拡張点；実体は authz・validation・throttle などのリスナー

### ランタイムアダプタ {#runtime-adapters}

`Switon\Http\ServerOptions` で待受ホスト・ポート・アダプタ種別・固有設定。既定は `type: auto`。

---

## 設定 {#configuration}

`switon.yml` で HTTP 契約を設定。

```yaml
# switon.yml
Switon\Http\ServerOptions:
  type: auto
  host: 0.0.0.0
  port: 9501
```

### オプションの確認 {#option-discovery}

```bash
bash bin/console config:options 'Switon\Http\ServerOptions'
```

---

## 注意点 {#keep-in-mind}

- `RequestInterface::all()` はルート・POST・クエリをマージ。
- `RequestInterface::get()` は `null` を欠損扱い。
- `Response::redirect()` は意図的に現在フローを止める。
- リクエスト全体の関心はリスナーに置き、コントローラプロパティに散らさない。

## 次のステップ {#next}

- **ルート・アクション・型付き入力:** [Controller](controller.md)
- **入力とメタデータ:** [Request](request.md)
- **レスポンス・ダウンロード・ストリーミング:** [Response](response.md)
- **サーバーアダプタとランタイム:** [Server](server.md)
- **よくある質問:** [FAQ](faq.md)
- **設計のトレードオフ:** [設計の狙い](design.md)
