---
sidebar_label: 型付き入力バインド
description: RequestBody/Query/Data とバインド時検証、ネストオブジェクト。
---

# 型付き入力バインド {#typed-input-binding}

ソース別属性と検証でリクエストデータを型付き入力クラスにバインドする。

**主な機能:**

- **ソース選択** — `#[RequestBody]`・`#[RequestQuery]`・`#[RequestData]`
- **ルートクラス契約** — 属性は入力クラス根に付け、ネストプロパティには付けない
- **バインド時検証** — public プロパティをまとめて投入・検証
- **ネストと配列** — ネストオブジェクトと型付き配列を含められる

## クイックスタート {#quick-start}

入力クラスに属性を付け、アクションパラメータに使う。

```php
namespace App\Input;

use Switon\Http\Attribute\RequestBody;
use Switon\Routing\Attribute\PostMapping;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Validating\Attribute\Email;
use Switon\Validating\Attribute\Length;

#[RequestBody]
class CreateUserInput
{
    #[Length(4, 16)]
    public string $username;

    #[Email]
    public string $email;
}

#[RequestMapping('/user')]
class UserController
{
    #[PostMapping('')]
    public function createAction(CreateUserInput $input): array
    {
        return ['username' => $input->username, 'email' => $input->email];
    }
}
```

ボディは `#[RequestBody]`、クエリは `#[RequestQuery]`、マージ契約は `#[RequestData]`。

## コア概念 {#core-concepts}

### ソース属性 {#source-attributes}

3 属性がバインダが使うソース配列を選ぶ。

- `#[RequestBody]` — 解析済みボディ
- `#[RequestQuery]` — クエリ
- `#[RequestData]` — マージ済み

### バインドモデル {#binding-model}

ルート入力クラス上で動作。

- public プロパティにリクエスト値
- 必須欠損は検証失敗
- ネストオブジェクトは再帰的に投入

パラメータ解決の基盤は [Binding パッケージ](/ja-JP/binding/) を参照。

### 検証 {#validation}

検証属性はバインド時に実行。

- `Switon\Validating\Attribute\Length`
- `Switon\Validating\Attribute\Email`
- `Switon\Validating\Attribute\Required` — 既定ルールで足りないときだけ

検証パッケージの詳細は [英語版 validation](/en/validation/) を参照。

### 配列とネストオブジェクト {#arrays-nested-objects}

- ネストフィールドのエラーキー例: `user.name`
- 配列は通常バインダ経路で各要素を検証可能

## 注意点 {#keep-in-mind}

- 型付き入力属性が要るのはルート入力クラスのみ。
- `RequestBody` と `RequestQuery` はソースを分ける。
- マージ入力が意図した契約のときだけ `RequestData`。

## 次のステップ {#next}

- **コントローラでの利用:** [Controller](controller.md)
- **生リクエスト読み取り:** [Request](request.md)
- **HTTP FAQ:** [FAQ](faq.md)
