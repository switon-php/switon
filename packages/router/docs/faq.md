# Router FAQ {#router-faq}

Short answers for setup and common routing issues.

> **Prerequisites:** [Router Package](README.md)

## Getting Started {#getting-started}

### Why is my controller not discovered? {#controller-not-discovered}

Check the scan rules and make sure the class has class-level `#[RequestMapping]`.

### What is the difference between `#[RequestMapping]` and `#[RequestMapping('')]`? {#request-mapping-empty}

Omitted or `null` derives a prefix from the controller name in `kebab-case`. `''` keeps the controller prefix empty.

### What is the difference between `#[GetMapping]` and `#[GetMapping('')]`? {#get-mapping-empty}

`#[GetMapping]` derives the method path from the method name in `kebab-case`. `#[GetMapping('')]` uses only the controller prefix.

### Does Router cast `{id}` into `int $id`? {#router-cast}

No. Router only matches paths and extracts strings.

## Paths {#paths}

### Why does `/api/v1/users` not match my route? {#prefix-mismatch}

Set the prefix on Router, then keep the stored route patterns prefix-free.

For example, with `setPrefix('/api/v1')`, register `/users`, not `/api/v1/users`.

If you use a conditional prefix, the URI must match the configured pattern or `getPrefix($uri)` can throw `NotFoundRouteException`.

### How do array prefixes and array paths behave? {#array-paths}

Router registers every combination.

### Why does an absolute method path ignore the controller prefix? {#absolute-path}

A method path that starts with `/` is treated as absolute.

## Commands {#commands}

### How do I inspect all routes? {#inspect-routes}

Use `bash bin/console router:list`.

The optional filter matches `VERB path` and supports substrings or wildcards.

### How do I resolve one path? {#resolve-path}

Use `bash bin/console router:resolve /api/users/1 GET`.

If you omit the verb, `router:resolve` tries the common HTTP verbs in order and may return more than one match.

### How do I check the current prefix? {#check-prefix}

Use `bash bin/console router:prefix`.
