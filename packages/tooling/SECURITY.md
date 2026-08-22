# Security Policy

## Supported Versions

Security fixes are provided for **currently supported release lines** of `switon/tooling` (`switon/tooling` on Packagist).

| Version line | Supported |
|--------------|-----------|
| `1.x` (latest stable release) | Yes |
| Older major/minor lines with no maintained tag | No |

Check the latest release tag (for example `v1.0.1` on this repository) before reporting.

## Reporting a Vulnerability

**Please do not open a public GitHub issue for security vulnerabilities.**

Use one of these channels:

1. **GitHub private vulnerability reporting (preferred)**  
   [Report a vulnerability](https://github.com/switon-php/tooling/security/advisories/new)

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

`bash` runs the command string in a new bash (`bash -lc`). That string is the script. File tools take an absolute path and otherwise use the workspace; the workspace is the default directory, not a jail. The catch-all exec tool is different: it runs an allowlisted program as an argument list, with no shell. Spill files must be relative to the thread datadir, and a path containing `..` is rejected.

A real report is a file or exec tool that joins its path into a shell string, a spill path that leaves the datadir, or an id that was not allowlisted and still reaches a shell.

The following are generally **not** treated as security vulnerabilities here:

- Reports against unsupported or unmaintained version lines
- Issues that require misconfiguration or deployment choices outside documented guidance
- Denial-of-service scenarios with no practical impact on confidentiality or integrity at default settings
- Vulnerabilities in third-party dependencies already fixed in a newer supported release of that dependency (please report upstream; we will bump dependencies as part of maintenance)
- The command passed to `bash`. One fresh shell per call is the tool. Confine which agents may call it in the host, not by expecting this package to parse the script
- An absolute path passed to read, write, list, search, or delete. The caller chose that path
- A program name on the exec allowlist. The host set `$commands`; an allowed id runs that program

For non-security bugs and feature requests, use the normal issue tracker on this repository.
