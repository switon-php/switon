# Admin Area

The Admin area now focuses on operator identity and self-service flows. It is no longer the catch-all workbench or
message-center area.

## Scope

- Login and logout
- Password change, forgot-password, and reset flows
- Audit read pages for login logs and action logs
- Small sample utility pages that still live near operator identity, such as account registration and mail template preview

## Current Controllers

- `SessionController` handles login, logout, failed-login lockout, and single-session kicking.
- `PasswordController` handles password change, forgot-password, reset, and captcha support for recovery.
- `LoginLogController` and `ActionLogController` expose audit read pages.
- `AccountController` and `MailController` currently remain as auxiliary sample pages, not as the recommended primary flow.

## Main Routes

- `/login`
- `/admin/password/change`
- `/admin/password/forget`
- `/admin/password/reset`
- `/admin/login-log/index`
- `/admin/action-log/index`

## Security Baseline

- Passwords use `bcrypt`.
- Login failure is counted per account.
- Repeated failures trigger a temporary lock window.
- A new successful login destroys the previous stored session for the same account.
- Password changes and resets go through one shared password-policy service.

This area stays intentionally narrow: identity, password lifecycle, and self-service account pages belong here; role and
permission modeling stays in RBAC, and navigation structure stays in Menu.

## Related Areas

- [Database specification](database.md) for tables, keys, and seed data.
- [UI surfaces](ui.md) for page ownership and list defaults.
- [Troubleshooting](troubleshooting.md) for login and password-flow checks.
- [RBAC area](../rbac/README.md) for roles, permissions, and authorization boundaries.
- [Audit Log area](../audit-log/README.md) for the log-oriented read side of operator activity.
