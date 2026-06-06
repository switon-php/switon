---
sidebar_label: Context 実装の深掘り
description: FPM/Swoole で同じ隔離を実現する Context の進化（8ステップ）。
---

# Context 実装の深掘り

FPM と Swoole の両方で、透過的かつ同一のリクエスト隔離を実現する Switon の仕組み。

基本的な使い方は [Context パッケージ](../README.md) を参照してください。

## はじめに {#introduction}

このドキュメントでは、Context がなぜ必要か、仕組みが段階的にどう進化するか、そして Switon が FPM と Swoole でどのように振る舞いを揃えるかを説明します。

API の使い方ではなく、実装の根拠を知りたいときに読んでください。

---

## なぜ Context が重要か {#why-context-matters}

長寿命ランタイムにおいて、Context による隔離がなぜ重要か。

### PHP-FPM の世界 {#php-fpm-world}

従来の PHP-FPM は、プロセスあたり 1 リクエストで動きます。本質的な性質は、**自動的な隔離** です。

```php
class RequestState
{
    protected array $attributes = [];

    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }

    public function getAttribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }
}
```

FPM では各リクエストが独自のメモリ空間を持ちます。リクエストが終わると、プロセスのライフサイクルとともに状態も解放されます。あるリクエストが別リクエストの状態を読むことはありません。

### Swoole の課題 {#the-swoole-challenge}

Swoole は長寿命プロセス上で多数のコルーチンを並行実行します。スループットとリソース再利用が向上します。

- プロセス生成のオーバーヘッドがない
- 永続接続（データベース、Redis など）
- 真の非同期 I/O
- 1 万接続以上の同時処理

一方で根本的な問題が生じます。**メモリの共有** です。

```php
// Same RequestState class from above

// Request 1 (Coroutine #1): $request->setAttribute('user_id', 123);
// Request 2 (Coroutine #2): $request->setAttribute('user_id', 456);
// Request 1 reads: $request->getAttribute('user_id'); // Returns 456! ❌
```

複数のコルーチンが同じ `RequestState` インスタンスを共有すると、`$attributes` も共有されます。本番では、リクエスト間の状態漏洩として現れます。

核心的な問いは、ランタイムごとのビジネスコード分岐なしに、Swoole でも FPM と同様の隔離を保てるか、という点です。

### 理想の解 {#the-ideal-solution}

求める Context システムは次のとおりです。

- **透過的** — FPM と Swoole で同じコードが動き、`if (swoole_running())` のような分岐が不要
- **自動** — コルーチン ID の手動追跡や Context の手渡しが不要
- **安全** — リクエスト間で状態を誤って共有できない
- **高速** — オーバーヘッドが小さく、性能ペナルティがない
- **柔軟** — 継承による共有、子の独立状態、アダプタ所有の接続ライフタイムマーカーに対応
- **ゼロ設定** — そのまま使え、セットアップ不要
- **開発者に優しい** — きれいな API、IDE 支援、デバッグしやすい
- **集約・検査可能** — すべての Context 状態を専用クラスに集約し、監査・デバッグしやすい

Switon の Context システムは、これらの目標を満たすように設計されています。

---

## 概要 {#overview}

これはソースの行単位マップではなく、ウォークスルーです。`Coroutine::getContext()` から始め、名前空間、Context クラス、発見、デュアルランタイムの振る舞いを段階的に積み上げます。

本番の振る舞いについては次を参照してください。

- [Context パッケージ README](../README.md) — `ContextAware`、`ContextManager`、分離モード、継承。
- [Context 設計](../design.md) — 格納モデルとランタイムの振る舞い。

---

## 根本的な問題 {#problem}

この設計が解く、並行性の中核問題。

### FPM: 自然な隔離 {#fpm-natural-isolation}

従来の PHP-FPM では、各リクエストが別プロセスで実行されます。

```php
class RequestState
{
    protected array $attributes = [];

    public function setAttribute(string $name, mixed $value): void
    {
        $this->attributes[$name] = $value;
    }
}

// Request 1 (Process A): $request->attributes = ['user_id' => 123]
// Request 2 (Process B): $request->attributes = ['user_id' => 456]
// ✅ No conflict: Different processes, different memory
```

### Swoole: 共有メモリの問題 {#swoole-memory-problem}

Swoole では、同一プロセス内の複数リクエストがコルーチンとして動きます。

```php
// Same RequestState class

// Request 1 (Coroutine #1): $request->attributes = ['user_id' => 123]
// Request 2 (Coroutine #2): $request->attributes = ['user_id' => 456]
// ❌ Request 1 now sees user_id = 456 (data corruption!)
```

**課題:** アプリケーションコードを変えずに、リクエスト単位の状態をどう隔離するか。

---

## 進化: 素朴な実装から本番へ {#evolution}

この節では Context システムを段階的に組み立て、各段階で何が壊れるかを記録します。

各ステップは 1 つの問題を直し、次の制約を露わにします。この積み上げが、なぜ本番設計に中央管理・分離モード・クリーンアップフックが必要なのかを説明します。

最後まで読めば、本番の各機能が防ぐ具体的な失敗モードに対応づけられます。

**8 ステップ:**

1. 基本的な Swoole Context（名前空間の衝突）
2. `spl_object_id()` で複数インスタンスを扱う（衝突の解消）
3. 複数プロパティのサポート（Context クラスで状態を集約）
4. アクセスの簡略化（`__get()` マジックメソッド）
5. 自動検出（命名規則）
6. 継承の扱い（クラス階層をたどって検索）
7. 型システムの利用（リフレクションで戻り値型を読む）
8. FPM のサポート（`Runtime::isCoroutineEnabled()` を確認）

### ステップ 1: 基本的な Swoole Context {#step1}

`Coroutine::getContext()` はコルーチンローカル格納（`ArrayObject`）を提供します。基本的な隔離は解けますが、直接使うとすぐに保守が難しくなります。

**シンプルな使い方:**

```php
class RequestState
{
    public function setAttribute(string $name, mixed $value): void
    {
        $context = \Swoole\Coroutine::getContext();
        $context[$name] = $value;
    }

    public function getAttribute(string $name): mixed
    {
        $context = \Swoole\Coroutine::getContext();
        return $context[$name] ?? null;
    }
}

// Request 1 (Coroutine #1): Gets its own context storage
// Request 2 (Coroutine #2): Gets a different context storage
// ✅ Isolated: Each coroutine has separate data
```

この第一版には、まだ 4 つの問題があります。

**問題 1: 名前空間の衝突**

コンポーネントが同じキー名を使うと、互いのデータを壊します。

```php
class RequestState
{
    public function setAttribute(string $name, mixed $value): void
    {
        $context = \Swoole\Coroutine::getContext();
        $context[$name] = $value;  // Uses string key
    }
}

class SessionState
{
    public function set(string $key, mixed $value): void
    {
        $context = \Swoole\Coroutine::getContext();
        $context[$key] = $value;  // Also uses string key - collision! ❌
    }
}

// Both components corrupt each other's data
$request = new RequestState();
$session = new SessionState();
$request->setAttribute('user_id', 123);  // Sets 'user_id'
$session->set('user_id', 456);           // Overwrites 'user_id'!
$request->getAttribute('user_id');       // Returns 456 instead of 123 ❌
```

**問題 2: 複数インスタンスが状態を共有**

同じクラスの複数インスタンスでも干渉します。

```php
$request1 = new RequestState();
$request2 = new RequestState();
$request1->setAttribute('user_id', 123);  // Sets 'user_id'
$request2->setAttribute('user_id', 456);  // Overwrites same 'user_id'!
$request1->getAttribute('user_id');       // Returns 456 instead of 123 ❌
```

**問題 3: Swoole でしか動かない**

`Coroutine::getContext()` は FPM に存在しないため、従来の PHP 環境ではクラッシュします。

**問題 4: 型安全性がない**

文字列キーでは IDE の補完や型チェックが効かず、どのキーがあり何型を格納すべきか分かりません。

**根本原因:** すべてのコンポーネントが、隔離機構のない同一のフラットなキー・値格納を共有している。

### ステップ 2: 複数インスタンスと名前空間 {#step2}

第一版は 1 つのフラットな格納マップを共有します。クラスが違っても同じキーを上書きでき、同一クラスの複数インスタンスも衝突します。

**解決策:** 各オブジェクトの一意 ID をキーに使う。

#### PHP の機能: `spl_object_id()` {#php-object-id}

PHP は各オブジェクトインスタンスに対し一意の整数を返す組み込み関数を提供します。

```php
$obj1 = new stdClass();
$obj2 = new stdClass();

echo spl_object_id($obj1);  // e.g., 1
echo spl_object_id($obj2);  // e.g., 2

// Same object = same ID
$obj3 = $obj1;
echo spl_object_id($obj3);  // 1 (same as $obj1)
```

**主な性質:**

- **インスタンスごとに一意** — 生存中のオブジェクトはそれぞれ異なる ID
- **安定** — オブジェクトの生存期間中 ID は変わらない
- **高速** — ネイティブの O(1) ルックアップ
- **整数** — 配列キーとして効率的
- **破棄後の再利用** — その ID に紐づく格納も所有者のライフサイクルで片付く場合にのみ安全

これにより、コンポーネント間の手動調整なしで自動的な名前空間分けが得られます。

#### 実装 {#implementation}

```php
class RequestState
{
    public function setAttribute(string $name, mixed $value): void
    {
        $context = \Swoole\Coroutine::getContext();
        $oid = spl_object_id($this);  // e.g., 42
        $context[$oid][$name] = $value;
    }

    public function getAttribute(string $name): mixed
    {
        $context = \Swoole\Coroutine::getContext();
        $oid = spl_object_id($this);
        return $context[$oid][$name] ?? null;
    }
}

// Now works with multiple instances
$request1 = new RequestState();  // Object ID: 42
$request2 = new RequestState();  // Object ID: 87
$request1->setAttribute('user_id', 123); // $context[42]['user_id'] = 123
$request2->setAttribute('user_id', 456); // $context[87]['user_id'] = 456
```

これで 2 点を同時に解決します。

**複数インスタンスが正しく動作** — 各インスタンスが一意のオブジェクト ID を持つ

**名前空間の衝突を解消** — コンポーネントごとにオブジェクト ID が異なる

各コンポーネントインスタンスが独自のオブジェクト ID バケットに書き込むため、名前空間の衝突は起きません。

```php
class RequestState
{
    public function setAttribute(string $name, mixed $value): void
    {
        $context = \Swoole\Coroutine::getContext();
        $oid = spl_object_id($this); // e.g., 42
        $context[$oid][$name] = $value;
    }
}

class SessionState
{
    public function set(string $key, mixed $value): void
    {
        $context = \Swoole\Coroutine::getContext();
        $oid = spl_object_id($this); // e.g., 87 (different!)
        $context[$oid][$key] = $value;
    }
}

// No collision - different object IDs
$request = new RequestState(); // Object ID: 42
$session = new SessionState(); // Object ID: 87
$request->setAttribute('user_id', 123); // $context[42]['user_id'] = 123
$session->set('user_id', 456);          // $context[87]['user_id'] = 456
$request->getAttribute('user_id');      // Returns 123 ✅ (reads $context[42])
```

**解決した問題:**

- 複数インスタンスが正しく動作
- 名前空間の衝突を解消

**残る問題:**

- 依然として FPM では動かない
- 状態がコード全体に散在（監査しづらい）
- Context アクセスの繰り返しコード
- **監査不可能** — 1000 行のクラスで、どの Context キーを使うか、すべての `setAttribute()` 呼び出しを追わないと分からない

残る課題は発見可能性です。状態キーは依然としてメソッドに散らばっています。

### ステップ 3: 複数プロパティのサポート {#step3}

インスタンス隔離は動きますが、状態定義はまだ散在しています。実際のコンポーネントは複数フィールド（ユーザー、セッション、属性など）を追跡することが多く、文字列キーではそのモデルが見えません。

解決策は、状態を一か所で宣言する専用の Context クラスです。

```php
class RequestStateContext
{
    public ?User $user = null;
    public array $attributes = [];
    public string $sessionId = '';
}

class RequestState
{
    public function setUser(User $user): void
    {
        $context = \Swoole\Coroutine::getContext();
        $oid = spl_object_id($this);

        if (!isset($context[$oid])) {
            $context[$oid] = new RequestStateContext();
        }

        $context[$oid]->user = $user;
    }

    public function setAttribute(string $name, mixed $value): void
    {
        $context = \Swoole\Coroutine::getContext();
        $oid = spl_object_id($this);

        if (!isset($context[$oid])) {
            $context[$oid] = new RequestStateContext();
        }

        $context[$oid]->attributes[$name] = $value;
    }
}
```

**なぜ別の Context クラスを使うのか**

1. **型安全** — `'user_id'` や `'session_id'` のようなマジック文字列が不要
2. **IDE 支援** — 補完が効き、タイポはコンパイル時に検出
3. **構造が明確** — コンポーネントが必要とする状態が一目で分かる
4. **関心の分離** — Context（状態）とロジック（メソッド）を分離
5. **監査しやすい** — すべての状態を一か所で宣言

大きなクラスでは特に有用です。

```php
// ❌ String keys - must read entire class to find all state
class RequestState  // 1000 lines
{
    public function method1() {
        $this->context['user_id'] = ...;  // Line 50
    }

    public function method2() {
        $this->context['session_id'] = ...;  // Line 300
    }

    public function method3() {
        $this->context['attributes'] = ...;  // Line 800
    }

    // What state does this class use? You must read all 1000 lines!
}

// ✅ Context class - all state declared in one place
class RequestStateContext  // 5 lines
{
    public ?User $user = null;
    public array $attributes = [];
    public string $sessionId = '';
    // All state visible at a glance!
}

class RequestState  // 1000 lines
{
    // Business logic only, no state declarations scattered around
}
```

Switon のマーカーと組み合わせると、コードレビューが体系的になります。

Swoole ではすべてのプロパティが危険というわけではありません。Switon はマーカーでレビューをしやすくします。

- **`#[Autowired]`** — 依存性注入（通常は設定、ステートレスサービス、または可変状態を Context オブジェクトに持つサービス）
- **`#[Communal]`**（`Switon\Core\Attribute\Communal`）— レビュー用マーカー: 長寿命サービスインスタンス上で共有して安全と確認済みのプロパティ
- **Context クラス** — スコープ単位の状態（本番では `ContextManager` が隔離）
- **マーカーなし** — Swoole 系デプロイでは要レビューとして扱う

> **`#[Communal]`** マーカーについては [Core パッケージ](/ja-JP/core/#php-attributes) を参照してください。

Context クラスはデータコンテナです。所有者コンポーネントがアクセスを制御するため、public プロパティで十分です。

**解決した問題:**

- インスタンスあたり複数プロパティ
- 型安全（マジック文字列なし）
- IDE 支援の向上
- **監査しやすい** — すべての状態を一か所で宣言

**残る問題:**

- 依然として FPM では動かない
- Context アクセスの繰り返し（各メソッドで同じ 5 行が必要）

### ステップ 4: Context アクセスの簡略化 {#step4}

抽象化がないと、各メソッドが同じ Context アクセスの定型文を繰り返します。

解決策は、格納のルックアップを `__get()` の背後に隠すことです。

存在しないプロパティにアクセスすると `__get()` が呼ばれます。これを使い、`$this->context` が正しい Context オブジェクトを自動返却するようにできます。

```php
class RequestStateContext
{
    public ?User $user = null;
    public array $attributes = [];
}

/**
 * @property-read RequestStateContext $context
 */
class RequestState
{
    public function __get(string $name)
    {
        if ($name === 'context') {
            $ctx = \Swoole\Coroutine::getContext();
            $oid = spl_object_id($this);

            if (!isset($ctx[$oid])) {
                $ctx[$oid] = new RequestStateContext();
            }

            return $ctx[$oid];
        }

        throw new \Exception("Property {$name} not found");
    }

    public function setUser(User $user): void
    {
        $this->context->user = $user;  // ✅ Clean!
    }

    public function setAttribute(string $name, mixed $value): void
    {
        $this->context->attributes[$name] = $value;  // ✅ Clean!
    }
}
```

流れ:

1. `$this->context` と書くと、PHP は `$context` プロパティがないと判断する
2. PHP が `__get('context')` を呼ぶ
3. `__get()` が Swoole の格納から Context オブジェクトを取得（または作成）する
4. Context オブジェクトが返る
5. そのプロパティにアクセスできる: `$this->context->count`

`@property-read` は IDE に `$context` が存在し、どの型を返すかを伝えます。

**なぜ良いか:**

- ビジネスロジックが読みやすい
- 各メソッドに定型文が不要
- Context アクセスの書き忘れが起きにくい
- IDE が `$this->context` の型を理解する

**解決した問題:**

- きれいでシンプルな API
- ビジネスロジックから Context アクセスを隠蔽
- 保守するコード量の削減

**残る問題:**

- 依然として FPM では動かない
- 各クラスが `__get()` を実装する必要がある
- Context クラス名がハードコードされる

### ステップ 5: Context クラスの自動検出 {#step5}

ステップ 4 では依然 `new RequestStateContext()` がハードコードされ、コンポーネントごとに配線が必要です。

次は命名規則です。クラス名に `Context` を付加します。

```php
class RequestStateContext
{
    public ?User $user = null;
}

/**
 * @property-read RequestStateContext $context
 */
class RequestState
{
    public function __get(string $name)
    {
        if ($name === 'context') {
            $ctx = \Swoole\Coroutine::getContext();
            $oid = spl_object_id($this);

            if (!isset($ctx[$oid])) {
                // Auto-detect: ClassName + "Context"
                $contextClass = static::class . 'Context';

                if (!class_exists($contextClass)) {
                    throw new \Exception("Context class {$contextClass} not found");
                }

                $ctx[$oid] = new $contextClass();
            }

            return $ctx[$oid];
        }

        throw new \Exception("Property {$name} not found");
    }

    public function setUser(User $user): void
    {
        $this->context->user = $user;
    }
}

// Auto-resolved
$request = new RequestState();  // Auto-finds RequestStateContext
$request->setUser($user);
```

流れ:

1. `$this->context` にアクセスすると `__get('context')` が起動
2. `__get()` が Context クラス名を構築: `RequestState` + `Context` = `RequestStateContext`
3. クラスの存在を確認
4. インスタンスを作成して格納
5. 以降のアクセスは同じインスタンスを返す

利点:

- 自動 — 手動設定不要
- 規約ベース — 予測可能な命名
- 最小限の規約 — `Context` を付けるだけ

制限:

- 柔軟性が低い — Context クラスは命名規則に従う必要がある
- 型安全性が弱い — IDE は正確な型を知らない
- リファクタリングに弱い — リネームで規約が壊れる
- マジック文字列 — 文字列連結に依存

**残る問題:**

- 依然として FPM では動かない
- 継承を正しく扱えない
- 命名規則が硬い

### ステップ 6: 継承の扱い {#step6}

命名規則だけでは継承に弱いです。親クラスの利用は動きますが、派生 Context クラスがない子クラスでは失敗します。

- `RequestState` を直接使うと動作する
- `ExtendedRequestState` を使うと "Class not found" でクラッシュ
- 子クラス側にロジック変更は不要 — 継承だけ
- エラーは派生 Context クラス欠如を指す

**問題:**

```php
class RequestState
{
    // Uses naming convention: RequestState + "Context" = RequestStateContext
}

class RequestStateContext
{
    public ?User $user = null;
}

// Parent usage
$request = new RequestState();
$request->setUser($user);

// Child usage can fail without a derived context class
class ExtendedRequestState extends RequestState
{
    // Naming convention looks for: ExtendedRequestState + "Context" = ExtendedRequestStateContext
    // But ExtendedRequestStateContext doesn't exist!
}

$extended = new ExtendedRequestState();
$extended->setUser($user);  // Fatal error: ExtendedRequestStateContext not found
```

実務で失敗する理由:

1. **親は動く、子は壊れる** — 単純な継承で振る舞いが変わる
2. **エラー表面が間接的** — `__get()` 内で失敗する
3. **規約が暗黙** — 親クラスが要件を明示しない

**解決策:** クラス階層をたどり、最初に存在する Context クラスを見つける。

```php
class RequestStateContext
{
    public ?User $user = null;
}

/**
 * @property-read RequestStateContext $context
 */
class RequestState
{
    public function __get(string $name)
    {
        if ($name === 'context') {
            $ctx = \Swoole\Coroutine::getContext();
            $oid = spl_object_id($this);

            if (!isset($ctx[$oid])) {
                // Search up the class hierarchy
                $class = static::class;
                $contextClass = null;

                do {
                    $candidate = $class . 'Context';
                    if (class_exists($candidate)) {
                        $contextClass = $candidate;
                        break;
                    }
                    $class = get_parent_class($class);
                } while ($class !== false);

                if ($contextClass === null) {
                    throw new \Exception("Context class not found for " . static::class);
                }

                $ctx[$oid] = new $contextClass();
            }

            return $ctx[$oid];
        }

        throw new \Exception("Property {$name} not found");
    }

    public function setUser(User $user): void
    {
        $this->context->user = $user;
    }
}

// Child class reuses parent context
class ExtendedRequestState extends RequestState
{
    // No need to create ExtendedRequestStateContext
    // Automatically finds and uses RequestStateContext
}

// Works
$extended = new ExtendedRequestState();
$extended->setUser($user);  // Uses RequestStateContext from parent
```

**検索の仕組み:**

1. `ExtendedRequestState` がインスタンス化される
2. 初めて `$this->context` にアクセスすると `__get()` が起動
3. `ExtendedRequestState` から検索開始:
    - `ExtendedRequestStateContext` を試す — 存在しない
    - 親へ: `get_parent_class('ExtendedRequestState')` = `'RequestState'`
4. `RequestStateContext` を試し、あれば採用
5. `new RequestStateContext()` を作成して返す

子が追加の状態を必要とするとき:

子クラスが追加状態を必要とする場合もあります。

```php
class ExtendedRequestStateContext extends RequestStateContext
{
    public string $sessionId = '';  // Additional state
}

class ExtendedRequestState extends RequestState
{
    // Now ExtendedRequestStateContext exists, so it will be used
}
```

**解決した問題:**

- 継承が正しく動作
- 子クラスが親の Context を再利用できる
- 子クラスが独自の Context で上書きできる

**残る問題:**

- 依然として FPM では動かない
- 依然として命名規則（硬い、型安全性が弱い）

### ステップ 7: 型システムによる Context 発見 {#step7}

命名規則は暗黙で硬いままです。より良いのは、メソッドの戻り値型による明示的な型宣言です。

`getContext()` の戻り値型とリフレクションで Context クラスを発見します。

```php
class RequestStateContext
{
    public ?User $user = null;
}

/**
 * @property-read RequestStateContext $context
 */
class RequestState
{
    public function __get(string $name)
    {
        if ($name === 'context') {
            $ctx = \Swoole\Coroutine::getContext();
            $oid = spl_object_id($this);

            if (!isset($ctx[$oid])) {
                // Use reflection to read return type of getContext()
                $method = new \ReflectionMethod($this, 'getContext');
                $returnType = $method->getReturnType();

                if (!$returnType || $returnType->isBuiltin()) {
                    throw new \Exception("getContext() must have a class return type");
                }

                $contextClass = $returnType->getName();
                $ctx[$oid] = new $contextClass();
            }

            return $ctx[$oid];
        }

        throw new \Exception("Property {$name} not found");
    }

    // Declare return type - this is what reflection reads
    public function getContext(): RequestStateContext
    {
        return $this->context;
    }

    public function setUser(User $user): void
    {
        $this->getContext()->user = $user;
    }
}
```

流れ:

1. コンポーネントが戻り値型付きの `getContext()` を宣言: `RequestStateContext`
2. `$this->getContext()` 呼び出しで `$this->context` にアクセス
3. `$this->context` が `__get('context')` を起動
4. `__get()` がリフレクションで `getContext()` の戻り値型を読む
5. そのクラスのインスタンスを作成して格納
6. Context インスタンスを返す

継承の振る舞い:

PHP の `ReflectionMethod` は継承チェーンを自動的にたどります。

```php
class ExtendedRequestState extends RequestState
{
    // No need to redeclare getContext()
    // Automatically uses parent's RequestStateContext
}
```

子が別の Context 型を必要とするとき:

```php
class ExtendedRequestStateContext extends RequestStateContext
{
    public string $sessionId = '';
}

class ExtendedRequestState extends RequestState
{
    // Override to use different context
    public function getContext(): ExtendedRequestStateContext
    {
        return $this->context;
    }
}
```

改善点:

- **型安全** — コンパイラが戻り値型の存在を検査
- **IDE 支援** — 補完、定義へ移動、リファクタリングが効く
- **柔軟** — Context クラス名は任意（`XxxContext` に限定されない）
- **明示的** — 関係がコードで宣言される
- **リファクタリングに強い** — リネーム検出が自動で効く
- **継承に強い** — 子クラスは親の Context を自動継承

解決した問題:

- 命名規則が不要
- 型安全で IDE フレンドリー
- 柔軟な命名
- 継承も引き続き動作

残る問題:

- 依然として FPM では動かない

### ステップ 8: FPM と Swoole の両対応 {#step8}

前のステップは依然コルーチン API の存在を前提とします。FPM には別の格納バックエンドが必要です。

> **注:** Switon の `ContextManager` は拡張の読み込み有無ではなく、カーネルが設定するブートストラップフラグ `Runtime::isCoroutineEnabled()` で分岐します。フラグが有効なとき、コルーチン格納パスは実際のコルーチン Context を前提とします。

スケッチでは Swoole が有効ならコルーチン格納、そうでなければ FPM ではオブジェクト上に Context を保持します。

#### 実装 {#step8-implementation}

ステップ 7 のコードにチェックを 1 つ足すだけです。

```php
class RequestState
{
    protected ?object $_context = null;  // FPM storage

    public function __get(string $name)
    {
        if ($name === 'context') {
            // Check whether coroutine storage should be used
            if (\Switon\Core\Runtime::isCoroutineEnabled()) {
                // Coroutine runtime: use coroutine context
                $ctx = \Swoole\Coroutine::getContext();
                $oid = spl_object_id($this);

                if (!isset($ctx[$oid])) {
                    $ctx[$oid] = $this->createContext();
                }

                return $ctx[$oid];
            }
            // FPM: Store directly on object
            else {
                if ($this->_context === null) {
                    $this->_context = $this->createContext();
                }
                return $this->_context;
            }
        }

        throw new \Exception("Property {$name} not found");
    }

    // createContext() and getContext() same as Step 7
    // ...
}
```

要点は、アクセサは 1 つに保ち、ランタイムモードで格納バックエンドを切り替えることです。本番では `Runtime::isCoroutineEnabled()` により `ContextManager` に集約されます。

ランタイムの振る舞い:

コルーチンランタイム:

1. ランタイムモードがコルーチンローカル格納を許可
2. コルーチンごとの格納に `Coroutine::getContext()` を使用
3. 各コルーチンが隔離された Context を得る

FPM:

1. `extension_loaded('swoole')` は `false` を返す
2. Context を `$_context` プロパティに直接格納
3. 各リクエストは別プロセスで実行され、プロパティは自然に隔離される

得られるもの:

- **両環境で同じコンポーネント API**
- **FPM のオーバーヘッドが小さい** — プロパティへの直接格納
- **自動的な隔離経路** — FPM はプロセス隔離、Swoole はコルーチン隔離
- **コンポーネントごとのランタイム配線が不要**

解決した問題:

- FPM と Swoole の両方で動作
- FPM でオーバーヘッドゼロ（直接プロパティアクセス）
- ランタイムの自動検出
- 設定不要

ランタイム互換は解けますが、本番では依然中央管理が必要です。

残る問題:

- **定型コード** — 各クラスが `__get()`、`getContext()`、リフレクションロジックを実装する必要
- **中央管理がない** — デバッグのためすべてのアクティブ Context を検査できない
- **クリーンアップ機構がない** — 長寿命プロセスで Context オブジェクトがメモリリークしうる
- **単一の隔離戦略** — 一部の Context は別の分離モードが必要な場合は？
- **監視がない** — 作成・アクセスパターン・性能を追跡できない
- **テストしづらい** — Context ロジックがビジネスロジックと混在
- **継承の問題** — 子コルーチンは常に親 Context を継承（常に望ましいわけではない）
- **接続スコープの状態** — コルーチンのライフサイクルを越えて存続すべき状態がある

問題の例:

```php
// Problem 1: Some state should not be inherited
class TransactionContext
{
    public string $transactionId;  // Shared across child coroutines: wrong transaction scope
}

// Problem 2: Can't debug context state
// How do you inspect all active contexts?
// How do you know which components have context?

// Problem 3: Boilerplate repeated everywhere
class RequestState { /* __get(), getContext(), createContext() */ }
class SessionState { /* __get(), getContext(), createContext() */ }
class CacheState { /* __get(), getContext(), createContext() */ }
// Same code copied 100 times!
```

進化の過程は中核の仕組みを説明します。本番実装ではフレームワークレベルの管理が加わります。

利用ガイドとアーキテクチャは [Context パッケージ](../README.md) を参照してください。

---

## 次のステップ {#next}

- [Context パッケージ](../README.md) — 公開 API、分離モード、よくある落とし穴
- [設計の狙い](../design.md) — 安定した格納とライフサイクル
- [Context 内部](README.md) — このフォルダの読み方とノート一覧
