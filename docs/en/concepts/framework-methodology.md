---
description: "Understand the Switon framework methodology: standardize scattered usage boundaries without taking away business ownership from the application."
---

# Understand the Switon framework methodology {#framework-methodology}

Read this page when you want the shortest explanation of what Switon is trying to standardize across applications, and what it deliberately leaves to the application.

Switon is not trying to win by hiding more behavior behind bigger abstractions.
It tries to standardize the scattered usage boundaries of application code without taking away business ownership that should stay inside the application.

## One sentence version {#one-sentence-version}

Switon tries to make repeated usage boundaries look consistent across applications, while leaving business ownership, storage shape, and product policy with the application unless the framework can standardize them cleanly.

## What this means in practice {#means-practice}

Switon usually tries to make these places converge first:

- controller and action boundaries
- request input and validation boundaries
- rendering and response boundaries
- command, worker, and scheduled-task boundaries
- other entry-side usage patterns that would otherwise drift across projects

These are the places developers copy most often, review most often, and ask AI to generate most often.
If those boundaries converge, applications already start to look like they belong to the same framework even when their business models differ.

## What Switon usually does not try to own {#try-own}

Switon does not try to force one application-specific storage model, one business policy model, or one domain structure when the framework cannot standardize it cleanly.

That usually means:

- business ownership stays in the application
- policy choices stay in the application unless the framework can offer a clear default boundary
- storage shape stays in the application unless a component can standardize it without becoming a hidden app template

This is why many Switon components spend so much time on boundaries. The framework is trying to reduce scattered usage drift, not to take over every implementation choice.

## Why this matters for package design {#design}

This methodology explains several recurring Switon package decisions:

- one package standardizes usage but avoids forcing one storage model
- one package offers transport or execution primitives but leaves delivery policy to the application
- one package keeps the public contract small and pushes business-specific composition outward

So when you read a Switon `design.md`, the key question is not only "how does this work?" but also "what scattered application problem is this package trying to make more consistent?"

## Why this matters for docs {#docs}

Switon documentation follows the same methodology.

- `README.md` helps you adopt the package boundary
- `faq.md` helps you recover when blocked at that boundary
- `design.md` explains why the framework wants that boundary at all

The three files are only the visible result. The deeper goal is to help readers:

- choose the right boundary
- unblock quickly
- internalize the framework's standardization logic

