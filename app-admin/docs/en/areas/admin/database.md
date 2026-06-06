# Admin Database Specification

The Admin area stores operator accounts, login and action audit data, online-session state, and lightweight message-center records.

## Tables

- `admin`
- `admin_login_log`
- `admin_action_log`
- `admin_notice`
- `admin_message`
- `admin_todo`

## Core Account Fields

### `admin`

- `admin_id` (PK)
- `admin_name` (unique)
- `email` (unique)
- `status`, `type`, `tag`
- `avatar_id`
- `password`
- `white_ip`
- `login_ip`, `login_time`, `session_id`
- `fail_count`, `failed_at`, `locked_until`
- `created_by`, `updated_by`, `created_at`, `updated_at`

## Message-Center Fields

### `admin_notice`

- `notice_id` (PK)
- `notice_title`, `notice_body`
- `level`, `pin`, `status`
- `cover_id`, `attachment_id`
- `publish_at`, `expire_at`
- `created_by`, `updated_by`, `created_at`, `updated_at`

Indexes:
- `status_publish_at` on `(status, publish_at)`
- `pin_publish_at` on `(pin, publish_at)`

### `admin_message`

- `message_id` (PK)
- `admin_id`
- `message_title`, `message_body`
- `level`
- `sender_id`, `sender_name`
- `read_at`
- `created_by`, `updated_by`, `created_at`, `updated_at`

Indexes:
- `admin_read_at` on `(admin_id, read_at)`
- `created_at`

### `admin_todo`

- `todo_id` (PK)
- `admin_id`
- `todo_name`, `todo_body`
- `priority`, `status`
- `due_at`, `done_at`
- `created_by`, `updated_by`, `created_at`, `updated_at`

Indexes:
- `admin_status_priority` on `(admin_id, status, priority)`
- `due_at`

## Constraints

- Account identity is unique by `admin_name` and `email`.
- Password values are stored as `bcrypt` hashes.
- Message and todo rows are recipient-owned through `admin_id`.
- Notices are bulletin-style records; they are not recipient-owned.
- Message-center status and level values should stay backed by Dict type codes used by the UI and input validation.

## Seed Data

`install.sql` seeds:
- the default `admin` account
- one pinned notice
- one default message
- one default todo
- menu entries for profile, online sessions, notices, messages, and todos
