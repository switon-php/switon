# RPC FAQ {#rpc-faq}

Short answers for setup and common RPC issues.

> **Prerequisites:** [RPC Package](README.md)

## Getting Started {#getting-started}

### How do I make the first RPC call? {#first-call}

Inject `Switon\Rpc\RpcInterface`, call `call()`, and run `bash bin/console rpc:daemon` on the worker side.

### Does the method have to be `ClassName::method`? {#method-format}

No. You can also map a logical name through `methods`.

### Do parameters have to be positional? {#params}

No. Named maps are accepted too.

### How does the reply get back to the right caller? {#reply-matching}

Each call uses `correlation_id` and one process-level reply queue.

`correlation_id` is a monotonic string id in the caller process, and replies can arrive out of order.

## Configuration {#configuration}

### Where do I configure RPC? {#where-config}

Configure `Switon\Rpc\RpcInterface` in `switon.yml`.

### What are the defaults? {#defaults}

The default queue name is `rpc`, the default `methods` map is empty, the default `concurrency` is `8192`, and the default timeout is `3` seconds.

### How do I inspect the available options? {#discover-options}

Run `bash bin/console config:options 'Switon\Rpc\RpcInterface'`.

### What does `concurrency` control? {#concurrency}

It is the executor concurrency used by `daemon()`.

## Errors {#security-and-errors}

### When do I get `RpcRemoteException`? {#remote-exception}

When the worker sends a valid `error` reply.

### When do I get `RpcTimeoutException`? {#timeout}

When no matching reply arrives before the timeout.

### What must a reply body contain? {#reply-shape}

Either `result` or `error`.

`error` is serialized as `{ class, message, code }`, and callers treat non-integer `code` values as `0`.

## Operations {#operations}

### How do I start the worker? {#start-worker}

Run `bash bin/console rpc:daemon`.

The daemon pulls requests with a blocking queue read and hands handler work to the executor.

### Does RPC replace Queue or HTTP APIs? {#queue-or-http}

No. RPC is queue-backed request-reply for PHP callers and workers.
