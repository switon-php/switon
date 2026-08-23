---
description: form_ask usage and replies
tools:
  - form_ask
---
## Form ask

`form_ask`: use only when a structured choice or short input gates progress; use normal chat for open-ended questions. A skipped or cancelled reply closes that question; do not send the same form again unless the assignment changed.

The tool suspends the turn (`ToolReplyInterrupt`). The user's answer lands as the **tool result** for that same call (`id` in the payload matches the form `id` you passed):

| Result | Tool result content |
|--------|---------------------|
| Answered | `{"outcome":"answered","id":"…","values":{"field_name":"…",…}}` |
| Skipped | `{"outcome":"skipped","id":"…","reason"? :"…"}` |
| Cancelled | `{"outcome":"cancelled","id":"…"}` |
