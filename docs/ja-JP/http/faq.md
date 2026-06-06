---
sidebar_label: HTTP FAQ
description: ルーティング、入力、レスポンス、FPM と Swoole。
---

# HTTP FAQ {#http-faq}

セットアップとルーティング・入力・レスポンスへの短い回答です。

> **前提:** [HTTP パッケージ](README.md)

## コントローラ {#controllers}

### ルートが見つからない {#route-not-found}

まず `bash bin/console router:list`。ここに無ければ発見／マッピングの問題で、リクエスト処理ではない。

### アクションを小さく保つには？ {#keep-action-small}

まずルートパラメータとスカラーアクションパラメータ。ヘッダ・ファイル・メタデータが要るときだけ `RequestInterface`。

## 入力 {#input}

### `RequestInterface::all()` は何を返す？ {#request-all}

マージ入力: ルート・POST・クエリ。名前衝突はルートが勝つ。

### 型付き入力はいつ？ {#typed-input}

再利用・検証したいリクエスト形状には `#[RequestBody]`・`#[RequestQuery]`・`#[RequestData]`。

### `null` の扱いは？ {#null-values}

`get()` は `null` を欠損扱い。`null` を保持したいときはソース別読み取り。

## レスポンス {#response}

### いつ配列を返す？ {#return-array}

通常の JSON 成功経路は配列。ステータス・ヘッダ・リダイレクト・ダウンロード・ストリーミングが要るときだけ `ResponseInterface`。

### リダイレクトは？ {#redirect}

`Response::redirect()`。`Location` を準備し、意図的にフローを止める。

## ランタイム {#runtime}

### HTTP オプションの確認は？ {#inspect-options}

`bash bin/console config:options 'Switon\Http\ServerOptions'`。

### 同じコントローラを FPM と Swoole で？ {#fpm-and-swoole}

はい。リクエスト状態はリクエストごとに分離され、対応ランタイムで同じコントローラコードが動く。
