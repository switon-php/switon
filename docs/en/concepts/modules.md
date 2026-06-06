# Understand modules and areas {#modules}

Use this page when application structure has grown past one flat `app/` tree and you need a stable way to place feature code.

## Terms at a glance {#terms}

- **module** — a feature or reusable unit with a coherent purpose; it may be application-local or package/plugin-owned
- **area** — an application-local feature grouping, usually under `app/Areas/<Area>/`
- **entrypoint** — a controller, command, listener, task, or job class discovered by a runtime package
- **service boundary** — the class or contract that owns reusable business behavior behind entrypoints

## Areas in applications {#areas-in-applications}

Use an area when one feature has enough files that grouping by feature is clearer than grouping only by class type.

A common shape is:

```text
app/
  Areas/
    Billing/
      Controller/
      Service/
      Entity/
      Repository/
      Command/
      Job/
      Task/
      Listener/
```

This is still application code. The area name helps humans and discovery rules find related entrypoints, but it does not create a separate runtime container or a separate app.

Several package defaults already understand area paths, for example controller, listener, command, job, and task discovery patterns such as `@app/Areas/*/Controller/*Controller.php` or `@app/Areas/*/Job/*Job.php`.

## Modules beyond one app {#modules-beyond-app}

Use the word module for a broader reusable feature boundary.

A module may be:

- an application-local area that is not meant to be reused outside the app
- a Composer package that registers framework metadata through package-owned wiring
- a plugin-like feature that contributes routes, commands, listeners, or other entrypoints without changing the app's global file globs

The practical rule is ownership:

- if the feature belongs only to one app, start with an area
- if the feature should be versioned and installed across apps, move it toward a package/module boundary
- if the feature only adds one runtime entrypoint, avoid creating a module shape too early

## Placement rules {#placement-rules}

Keep entrypoints close to the runtime package that discovers them:

- HTTP controllers under `Controller/`
- CLI commands under `Command/`
- event listeners under `Listener/`
- queue jobs under `Job/`
- scheduled tasks under `Task/`

Keep reusable business behavior under service contracts. Controllers, commands, jobs, and tasks should usually call the same service when they perform the same business work.

Avoid using areas as a way to hide cross-feature coupling. If two areas need the same behavior, extract a service contract or move the shared behavior to the owner feature.
