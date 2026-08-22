# Security Policy

## Supported Versions

Security fixes are provided for **currently supported release lines** of `switon/llm` (`switon/llm` on Packagist).

| Version line | Supported |
|--------------|-----------|
| `1.x` (latest stable release) | Yes |
| Older major/minor lines with no maintained tag | No |

Check the latest release tag (for example `v1.0.1` on this repository) before reporting.

## Reporting a Vulnerability

**Please do not open a public GitHub issue for security vulnerabilities.**

Use one of these channels:

1. **GitHub private vulnerability reporting (preferred)**  
   [Report a vulnerability](https://github.com/switon-php/llm/security/advisories/new)

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

The client sends the chat to the configured `baseUrl` and, when set, sends `apiKey` on that request (`Authorization: Bearer` for OpenAI-compatible endpoints, `x-api-key` for Anthropic). The `llm` command prints the base URL. It does not print the key. A real report is the key showing up in command output, an exception, or a log line, or a request that goes somewhere other than the configured base URL.

Media refs are separate. A relative ref is read only under the given datadir, and `..` is rejected. An absolute ref is read as the caller wrote it, up to the byte cap. `http`, `https`, and `mm_file` are returned as URLs; this package does not fetch them.

The following are generally **not** treated as security vulnerabilities here:

- Reports against unsupported or unmaintained version lines
- Issues that require misconfiguration or deployment choices outside documented guidance
- Denial-of-service scenarios with no practical impact on confidentiality or integrity at default settings
- Vulnerabilities in third-party dependencies already fixed in a newer supported release of that dependency (please report upstream; we will bump dependencies as part of maintenance)
- The provider receiving the prompt. That is the call
- An absolute media path the caller put on the part. Relative refs stay under the datadir; an absolute path is the caller's choice
- The provider's own retention, training, or account security. Report that to the provider

For non-security bugs and feature requests, use the normal issue tracker on this repository.
