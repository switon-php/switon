# HTTP FAQ {#http-faq}
Short answers for setup and common routing, input, and response questions.

> **Prerequisites:** [HTTP Package](README.md)

## Controllers {#controllers}

### Why is my route not found? {#route-not-found}

Start with `bash bin/console router:list`. If the route is missing there, the problem is discovery or mapping, not request handling.

### How do I keep one controller action small? {#keep-action-small}

Use route parameters and scalar action parameters first.
Move to `Switon\Http\RequestInterface` only when the action needs headers, files, or request metadata.

## Input {#input}

### What does `RequestInterface::all()` return? {#request-all}

It returns merged input: route, POST, and query. Route values win on name collisions.

### When should I use typed input? {#typed-input}

Use `#[Switon\Http\Attribute\RequestBody]`, `#[Switon\Http\Attribute\RequestQuery]`, or `#[Switon\Http\Attribute\RequestData]` when one request shape should be reused and validated as a typed class.

### How do I handle `null` values? {#null-values}

`RequestInterface::get()` treats `null` like missing input. Use source-specific reads when `null` is a valid value you must preserve.

## Response {#response}

### When should I return an array? {#return-array}

Return an array for the normal JSON success path.
Use `Switon\Http\ResponseInterface` only when you need status, headers, redirects, downloads, or streaming.

### How do I send a redirect? {#redirect}

Call `Switon\Http\Response::redirect()`. It prepares the `Location` header and stops the flow intentionally.

## Runtime {#runtime}

### How do I inspect current HTTP options? {#inspect-options}

Run `bash bin/console config:options 'Switon\Http\ServerOptions'`.

### Can the same controller code run on FPM and Swoole? {#fpm-and-swoole}

Yes. The package keeps request state per request, so controller code stays the same across supported runtimes.
