# Admin UI Surfaces

The Admin area provides the default backoffice entry, account, audit, and message-center screens.

## Default Pages

- Login
- Password reset and password change
- Profile
- Workbench homepage
- Online sessions
- Login log and action log
- Notices
- Messages
- Todos

## Workbench

The workbench is rendered by `App\Controller\IndexController`. It aggregates counters, recent audit activity, notices, unread messages, and pending todos into the first operator screen after login.

Keep the workbench small:
- show action-oriented summaries
- link to owner pages for full lists
- avoid turning the homepage into a second copy of each module

## Account Pages

- Profile owns current-operator editable fields.
- Password pages own password policy feedback and reset flow.
- Online sessions owns current session visibility and operator kick actions.

Use self-scoped actions for current-operator pages, and permission-gated actions for operator-management pages.

## Message Center

- Notice pages manage bulletin-style announcements.
- Message pages use an inbox-first shape based on `admin_id`.
- Todo pages manage lightweight reminders for an operator.

Dict-backed page options should drive visible labels for notice levels, notice status, message levels, todo priority, and todo status.

## Table Defaults

- Keep list filters compact and operational.
- Keep action columns consistent across notice, message, and todo pages.
- Prefer typed input objects for create and edit dialogs.
- Use asset selectors only where the page needs a file-backed field, such as notice cover or attachment.

