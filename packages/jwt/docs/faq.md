# JWT FAQ {#jwt-faq}
Short answers for token boundaries, keys, and failures.

> **Prerequisites:** [JWT Package](README.md)

## Getting Started {#getting-started}

### Which interface should I inject? {#which-interface}

Inject `Switon\Jwt\JwtInterface`.

### What does `decode()` validate? {#decode-validates}

Token format, header `alg`, optional header `typ`, optional header `kid` type, payload structure, `kid` agreement, signature, and numeric `exp` / `nbf`. See [Trust Boundary](README.md#trust-boundary).

### When should I use `kid`? {#kid-usage}

Use `kid` when different tenants, services, or rollout versions need separate signing keys. See [Token Family Routing](README.md#token-family-routing).

### Why is there no `verify()` method? {#no-verify}

Because the package keeps one trust boundary on purpose. `decode()` is the only place where claims become trusted input.

## Configuration {#configuration}

### How do I configure JWT? {#configure}

Add a `Switon\Jwt\JwtInterface` block to `switon.yml`. Common options are `alg`, `key`, and `leeway`.

### How do I inspect the current option surface? {#discover-options}

Run `bash bin/console config:options 'Switon\Jwt\JwtInterface'`.

### Which algorithms are supported? {#supported-algorithms}

`HS256`, `HS384`, and `HS512`.

### What does `leeway` do? {#leeway}

Adds clock-skew tolerance, in seconds, to `exp` and `nbf` checks.

## Keys {#keys}

### What happens when `kid` is null? {#kid-null}

JWT uses the configured `key`. When `key` is null, JWT derives the default key from `jwt` through `Switon\Crypto\CipherInterface`.

### How should I configure `key`? {#key-format}

Use lowercase hex with an even number of characters. The configured hex length does not have to match the algorithm strength exactly.

### Should application code read the signing key directly? {#expose-key}

Usually no. Keep application code on `encode()` and `decode()` unless you explicitly need lower-level key material from `Switon\Crypto\CipherInterface`.

## Troubleshooting {#troubleshooting}

### Which exceptions should I catch first? {#catch-order}

Catch the failures that matter most at your boundary. A common order is `ExpiredException`, `KidMismatchException`, `SignatureException`, `MalformedException`, and `NotBeforeException`. Add `NoCredentialException` for empty tokens.

### Why do I get `SignatureException` after deployment? {#signature-after-deploy}

Check the effective key boundary first: configured `key`, expected `kid`, crypto master key, and whether the token came from a different issuer.

### Why do I get `AlgorithmMismatchException`? {#alg-mismatch}

The token header `alg` does not match the configured algorithm.

### What HTTP status do JWT failures use? {#http-status}

JWT exceptions map to HTTP `401`.

### How do JWT and authorization fit together? {#jwt-and-authz}

Validate the token with JWT, then enforce permissions with [Authorization](https://github.com/switon-php/authz/blob/main/docs/README.md).
