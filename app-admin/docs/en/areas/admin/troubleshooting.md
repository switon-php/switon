# Admin Troubleshooting

Use this page for first-pass diagnosis of login, workbench, session, and message-center issues.

## Login Fails

Check in order:

1. Confirm the account `status` allows login.
2. Confirm `locked_until` is not in the future.
3. Confirm `white_ip` allows the request IP, or is `*`.
4. Confirm the stored `password` is a `bcrypt` hash.

The default seed account is intended for local bootstrap only. Rotate it before treating the app as an environment baseline.

## Login Redirects Back To Login

Check:
- browser cookie storage for the session cookie
- `admin.session_id` for single-session replacement
- server-side session storage health
- request-hook ordering around authentication

If a second login succeeded elsewhere, the older session can be invalidated by design.

## Workbench Has Empty Blocks

Check:
- `admin_notice` has published rows visible for the current time
- `admin_message.admin_id` matches the current operator
- `admin_todo.admin_id` matches the current operator
- dict rows exist for notice, message, and todo type codes
- the related menu permission is assigned to the current role

## Message Or Todo List Does Not Show Current User Data

Confirm owner fields first:

```sql
SELECT message_id, admin_id, read_at FROM admin_message ORDER BY message_id DESC LIMIT 5;
SELECT todo_id, admin_id, status FROM admin_todo ORDER BY todo_id DESC LIMIT 5;
```

If rows exist for a different `admin_id`, the list is correctly self-scoped and the data belongs to another operator.

## Notice Cover Or Attachment Does Not Render

Check:
- `cover_id` or `attachment_id` points to an existing `file_asset.asset_id`
- the asset scene matches the UI selector
- the file still exists in the configured storage location

For upload and asset ownership details, see the File area and Asset package docs.

## Menu Entry Is Missing

Check:
- `menu_group` includes the message-center or personal-center group
- `menu_item.permission_code` matches the controller permission
- the current role contains that permission
- browser cache is refreshed after menu seed changes
