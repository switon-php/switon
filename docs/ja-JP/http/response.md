---
sidebar_label: Response
description: 配列返却の JSON 経路と ResponseInterface、レンダラ、ストリーミング。
---

# Response {#response}

通常はアクション返却値、明示制御が要るときは `ResponseInterface` で HTTP レスポンスを整形する。

**主な機能:**

- **配列返却** — よくある JSON フロー
- **明示制御** — ステータス・ヘッダ・Cookie・リダイレクト・ファイル・ボディ
- **レンダラ** — `XmlRendererInterface`・`CsvRendererInterface`・`AttachmentRendererInterface`
- **ストリーミング** — 単一バッファでない chunked 出力
- **中央確定** — 整形は HTTP ライフサイクルを通過

## クイックスタート {#quick-start}

通常の JSON は配列を返す。

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
}
```

ステータス・ヘッダ・リダイレクト・ダウンロード・ストリーミングが要るときは `ResponseInterface`。

## コア概念 {#core-concepts}

### 通常のレスポンス経路 {#normal-response-path}

配列返却が JSON のよくある経路。

- フレームワークが返却値を正規化
- アクションはアプリデータに集中
- 普通の JSON 形状ならこの経路

### アクション返却値 {#action-return-values}

| 返却型 | レスポンス | 典型用途 |
|---|---|---|
| `array` | 通常の JSON ペイロード | ほとんどのアクション |
| `null` | 空の成功ペイロード | 返すデータなし |
| `int` | 業務コードペイロード | 簡易ステータス |
| `string` | 文字列ペイロード | テキスト応答など |
| `ResponseInterface` | 既に処理済み | 明示制御 |
| `Throwable` | 例外フロー | HTTP 例外処理へ |

### 明示的レスポンス制御 {#explicit-response-control}

`ResponseInterface` 注入時:

- `setStatus()` — ステータスコード
- `setHeader()`・`setCookie()` — ヘッダと Cookie
- `redirect()` — リダイレクト準備とフロー停止
- `setFile()`・`write()` — ファイルと chunked 出力

### キャッシュ {#caching}

リクエスト形状キーでレスポンス全体を安全に再利用できるときだけレスポンスキャッシュ。

### レンダラ {#renderers}

出力形式自体が要件のとき:

- `Switon\Http\Response\XmlRendererInterface`
- `Switon\Http\Response\CsvRendererInterface`
- `Switon\Http\Response\AttachmentRendererInterface`

## 注意点 {#keep-in-mind}

- `Response::redirect()` は意図的に現在フローを止める。
- `ResponseInterface::json()` は明示 JSON 用。
- JSON 以外が目的のときだけレンダラ。

## 次のステップ {#next}

- **コントローラ:** [Controller](controller.md)
- **リクエスト入力:** [Request](request.md)
- **ランタイム設定:** [Server](server.md)
