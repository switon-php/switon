# Artifact Templates

Use these templates in:
`runtime/specflow/{component}/{task-id}/`

## SPEC_FREEZE.md

```md
# SPEC_FREEZE

## API_FILES
- packages/{component}/src/EntryInterface.php
- packages/{component}/src/RequestOrModelInterface.php
- packages/{component}/src/CollaboratorInterface.php

## EXCEPTION_CATALOG
- ValidationExceptionLeaf
- RuntimeExceptionLeaf
- DeliveryOrInfraExceptionLeaf

## API_SURFACE

## Entry Types
- interface/class:
- purpose (one line):

## Method Signatures (freeze first)
- `public function method(Type $a, ?Type $b = null): ReturnType;`

## Non-goals in this file
- no road-signs/guidance wording
- no config defaults table
- no migration narrative

## CONTRACT

## Component
- name:
- scope path:

## Interface Changes
- add:
- change:
- remove:

## Exception Contract
- validation errors:
- runtime errors:
- mapping policy:

## Compatibility
- source compatibility:
- behavior compatibility:
- migration notes:

## Freeze Summary
- api:
- exception:
- compatibility:

## CONFIG_MATRIX

| key | required | type | default | validate stage | notes |
|-----|----------|------|---------|----------------|-------|
|     | yes/no   |      |         | inject/send/runtime |       |

## Freeze Summary
- required keys:
- optional keys:
- removed defaults:
```

## ARCHITECTURE.md

```md
# ARCHITECTURE

## Class Relations
- entry:
- collaborators:
- events/exits:

## Responsibilities
- class A:
- class B:

## Changed Files
- path:
- reason:
```

## TEST_PLAN.md

```md
# TEST PLAN

## Happy Path
- case:
- expected:

## Boundary Cases
- case:
- expected:

## Failure Cases
- case:
- expected exception:

## Commands
- bash tests/run.sh {component}
```

## DOC_SYNC.md

```md
# DOC SYNC

## Must Sync
- packages/{component}/README.md
- docs/en/{component}/README.md
- docs/en/{component}/faq.md

## Change Points
- file:
- section:
- delta:

## Consistency Checklist
- impl matches docs
- config table matches code
- exception names match code
```

## Scope Delta (append to SPEC_FREEZE.md)

```md
## Scope Delta
1. what changed:
2. impacted interface/config/test docs:
3. continue now or defer:
```
