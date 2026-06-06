---
sidebar_label: Request
description: RequestInterface：マージ入力、ソース別読み取り、ファイル、型付き入力。
---

# Request {#request}

ルート・クエリ・ボディ・ヘッダ・ファイル・属性を **ひとつのリクエスト境界** で読む。

**主な機能:**

- **マージ入力** — `get()`・`has()`・`all()`・`only()`・`except()` が同一マップ上で動作
- **ソース別** — ソースが重要なときは `route()`・`query()`・`post()`
- **ファイル** — `files()`・`file()`・`hasFile()`
- **メタデータ** — `header()`・`headers()`・`server()`・`ip()`・`url()`・`path()`
- **型付き入力** — `#[RequestBody]` などで型付きオブジェクトへ

## クイックスタート {#quick-start}

生リクエストデータやメタデータが要るときは `RequestInterface` を注入。

```php
namespace App\Controller;

use Switon\Http\Controller;
use Switon\Http\RequestInterface;
use Switon\Routing\Attribute\GetMapping;
use Switon\Routing\Attribute\RequestMapping;

#[RequestMapping('/search')]
class SearchController extends Controller
{
    #[GetMapping('')]
    public function searchAction(RequestInterface $request): array
    {
        return [
            'keyword' => $request->get('keyword'),
            'page' => $request->query('page', 1),
            'ip' => $request->ip(),
        ];
    }
}
```

ソースが重要ならソース別読み取り。パースや検証が広がり始めたら型付き入力へ。

## コア概念 {#core-concepts}

### マージ入力 {#merged-input}

`all()` はルート・解析済み POST/ボディ・クエリをマージ。名前衝突はルートが勝つ。

`get()` と `has()` は同じマージマップを読む。

### ソース別読み取り {#source-specific-reads}

単一ソースだけが要るとき:

- `route()` — ルート値
- `query()` — クエリ文字列
- `post()` — 解析済みボディ

### ファイルとメタデータ {#files-and-metadata}

- `files()`・`file()` — アップロード
- `header()`・`headers()` — ヘッダ
- `server()` — サーバー変数
- `ip()`・`url()`・`path()`・`verb()`・`isAjax()` — よく使う事実

### 型付き入力への引き渡し {#typed-hand-off}

安定した入力形状にはアクション内の生読み取りではなく型付き入力クラスをバインド。

- `#[RequestBody]` — 解析済みボディ
- `#[RequestQuery]` — クエリ
- `#[RequestData]` — マージ済み

## 注意点 {#keep-in-mind}

- `get()` は `null` を欠損扱い。
- `all()` はルーティング後のマージストア。
- JSON ボディはマージ読み取り前に POST データへ解析される。

## 次のステップ {#next}

- **ルートとコントローラ:** [Controller](controller.md)
- **レスポンス:** [Response](response.md)
- **型付き入力の詳細:** [型付き入力バインド](typed-input-binding.md)
