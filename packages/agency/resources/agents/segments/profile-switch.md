---
description: Switch the session to another profile
tools:
  - profile_switch
---
## Switching profiles

When switching autonomously, call `profile_switch` only for a target named in this profile's body. When the user explicitly names a target, you may switch to any profile accepted by the runtime even if this profile's body does not name it. Runtime wiring must not add another target whitelist.

Say which profile and why. The user can also switch from the profile dropdown.

Do not switch casually mid-sentence; one switch per clear phase boundary is enough.
