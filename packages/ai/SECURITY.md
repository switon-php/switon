# Security Policy

## Supported Versions

Security fixes are provided for **currently supported release lines** of `switon/ai` (`switon/ai` on Packagist).

| Version line | Supported |
|--------------|-----------|
| `1.x` (latest stable release) | Yes |
| Older major/minor lines with no maintained tag | No |

Check the latest release tag (for example `v1.0.1` on this repository) before reporting.

## Reporting a Vulnerability

**Please do not open a public GitHub issue for security vulnerabilities.**

Use one of these channels:

1. **GitHub private vulnerability reporting (preferred)**  
   [Report a vulnerability](https://github.com/switon-php/ai/security/advisories/new)

2. **Email**  
   Send details to [admin@switon.dev](mailto:admin@switon.dev).

### What to Include

- Affected version (Composer constraint, tag, or commit)
- Clear description of the issue and impact
- Steps to reproduce, or a minimal proof of concept when possible
- Any suggested fix or mitigation you have already identified

## What We Will Do

- Acknowledge receipt as soon as practicable (typically within a few business days)
- Confirm whether the report affects supported versions
- Work on a fix and coordinated disclosure
- Publish a security advisory and patched release when appropriate
- Credit reporters in the advisory when they wish to be named

## Out of Scope

`switon/ai` is the wire contract: an envelope, a turn, messages, and tool-call types. It does not call a model, start a process, or write a thread. A real report is a bug in those types — for example a decoded turn or tool call that escapes the value it claims to be.

The following are generally **not** treated as security vulnerabilities here:

- Reports against unsupported or unmaintained version lines
- Issues that require misconfiguration or deployment choices outside documented guidance
- Denial-of-service scenarios with no practical impact on confidentiality or integrity at default settings
- Vulnerabilities in third-party dependencies already fixed in a newer supported release of that dependency (please report upstream; we will bump dependencies as part of maintenance)
- Instructions inside a letter or message. The text is data. Deciding whether the host follows it belongs to the agent loop, not to this package
- A tool the host put on the charter. `ToolInterface` describes a handler; the registry that actually calls it is `switon/tooling`

For non-security bugs and feature requests, use the normal issue tracker on this repository.
