# Security Policy

## Supported Versions

Security fixes are provided for **currently supported release lines** of `switon/llm-html` (`switon/llm-html` on Packagist).

| Version line | Supported |
|--------------|-----------|
| `1.x` (latest stable release) | Yes |
| Older major/minor lines with no maintained tag | No |

Check the latest release tag (for example `v1.0.1` on this repository) before reporting.

## Reporting a Vulnerability

**Please do not open a public GitHub issue for security vulnerabilities.**

Use one of these channels:

1. **GitHub private vulnerability reporting (preferred)**  
   [Report a vulnerability](https://github.com/switon-php/llm-html/security/advisories/new)

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

This package turns HTML into text or Markdown for a model. Before the DOM parse it removes `script` and `style` elements. Markdown links drop `javascript:` and `data:` URLs. A real report is one of those surviving into the result as an active URL or as a script body that the extractor claimed to remove.

It does not fetch the page, run JavaScript, or produce HTML that is safe to put back in a browser.

The following are generally **not** treated as security vulnerabilities here:

- Reports against unsupported or unmaintained version lines
- Issues that require misconfiguration or deployment choices outside documented guidance
- Denial-of-service scenarios with no practical impact on confidentiality or integrity at default settings
- Vulnerabilities in third-party dependencies already fixed in a newer supported release of that dependency (please report upstream; we will bump dependencies as part of maintenance)
- Hostile prose that remains as text. The words of the page are the content the caller asked to extract
- Using the Markdown as HTML. The result is text for a model, not a sanitized document for a browser

For non-security bugs and feature requests, use the normal issue tracker on this repository.
