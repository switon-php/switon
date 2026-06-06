---
sidebar_label: Binding パッケージ
description: Switon binding：メソッド引数、`#[ResolvedBy]`、スカラー入力、typed input。
---

# Binding パッケージ {#binding-package}

`switon/binding` は **メソッドの引数リスト** を、入力・型側ルール・コンテナ状態から組み立てます。型付きリクエスト DTO も同じバインディング層でハイドレートします。

**主な機能:**

- **型がルールを持つ:** `#[ResolvedBy(...)]` で具体的な型ごとの構築方法を決め、**パラメータごと** に繰り返さない。
- **組み込み例:** `Page` がこのパッケージの代表的な値オブジェクト。
- **アクション引数:** `ArgumentsBinderInterface` がひとつの `ReflectionMethod` に対応する位置引数リストを解決する。
- **型付き入力:** `InputBinderInterface` が配列ペイロードから型付き入力オブジェクトを組み立てる。
- **自分で組み立てる値:** `ArgumentResolvable` で値型自体が構築ロジックに参加できる。

## インストール {#installation}

**要件:** PHP 8.3 以上

```bash
composer require switon/binding
```

## クイックスタート {#quick-start}

### アクション引数をバインド {#bind-action-arguments}

`ArgumentsBinderInterface` で引数を解決し、`InvokerInterface` に渡して呼び出します。

```php
use ReflectionMethod;
use Switon\Binding\ArgumentsBinderInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Orm\Page;
use Switon\Invoking\InvokerInterface;

class ExampleController
{
    public function indexAction(Page $page): void
    {
    }
}

class Dispatcher
{
    #[Autowired] protected ArgumentsBinderInterface $argumentsBinder;
    #[Autowired] protected InvokerInterface $invoker;

    public function dispatch(object $controller, string $action): mixed
    {
        $method = new ReflectionMethod($controller, $action);
        $arguments = $this->argumentsBinder->resolve($method);

        return $this->invoker->invoke([$controller, $action], $arguments);
    }
}
```

- **バインディング** が「値をどこから取るか」を決める。
- **Invoker** が実際の呼び出しを行う。
- **`Page`** が「型がルールを持つ」パターンのわかりやすい組み込み例。

## コア概念 {#core-concepts}

### `#[ResolvedBy]` {#resolvedby}

具体的な型が **常に同じやり方で** 作られるときに `#[ResolvedBy(...)]` を使います。

- 属性は **クラス** に付ける。各パラメータには付けない。
- **`Page`** が最小の組み込み例。
- **`ArgumentResolvable`** は値型がルールを持つ別の形。
- 独自の型では `Page` と同じ形をまねる。

```php
use Switon\Binding\Attribute\ResolvedBy;
use Switon\Binding\ValueResolverInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\InputInterface;
use ReflectionParameter;
use Switon\Orm\Page;

#[ResolvedBy(PageResolver::class)]
class Page
{
    public static function of(int $page, int $limit = 10): static
    {
        // real class body omitted
    }
}

final class PageResolver implements ValueResolverInterface
{
    #[Autowired] protected InputInterface $input;

    public function resolve(ReflectionParameter $parameter, string $type): mixed
    {
        return Page::of(
            (int)$this->input->get('page', 1),
            (int)$this->input->get('size', 10),
        );
    }
}
```

リゾルバは入力から `page` と `size` を読み、値を組み立て、上限ルールも自分で適用します。

### コンテナからの値 {#container-values}

インターフェース型やほかに解決されなかったオブジェクトは **コンテナ** にフォールスルーします。

### スカラー入力 {#scalar-input}

スカラー引数は `Switon\Core\InputInterface` 由来です。

- まず **パラメータ名そのもの** で検索。
- 次に **`snake_case`** のキー。
- その後は有効な入力ソースの通常ルールに従う。
- 何も解決できなければ **既定値** まで進む。

### 型付き入力 {#typed-input}

リクエストデータからクラスを直接バインドするときは `#[RequestBody]`、`#[RequestQuery]`、`#[RequestData]` を使います。

```php
use Switon\Http\Attribute\RequestBody;

#[RequestBody]
class CreateUserInput
{
    public string $name;
    public ?string $email = null;
}

class UserController
{
    public function createAction(CreateUserInput $input): array
    {
        return ['name' => $input->name, 'email' => $input->email];
    }
}
```

- **`#[RequestBody]`** が書き込み系でよく使う既定。
- **データソースの境界** をはっきりさせたいときだけほかの属性を使う。

### オプションの確認 {#option-discovery}

```bash
bash bin/console config:options 'Switon\Binding\ScalarResolverInterface'
bash bin/console config:options 'Switon\Binding\ObjectResolverInterface'
```

## 注意点 {#common-pitfalls}

- `#[ResolvedBy]` は **クラス** に付ける。パラメータには付けない。
- 型側ルールが効かない **インターフェース** 型などは、従来どおり **コンテナ** で解決される。
- スカラーは `InputInterface` で **完全一致の名前** と **`snake_case`** フォールバック。
- バインディングは **引数リストまで**。実際の呼び出しは `InvokerInterface`。

## 次のステップ {#next}

- **バインドしたあと呼び出す:** [Invoker パッケージ](/en/invoker/)
- **HTTP ペイロードから typed input:** [HTTP の typed input binding](/en/http/typed-input-binding)
- **FAQ:** [FAQ](faq.md)
- **設計:** [設計の狙い](design.md)
