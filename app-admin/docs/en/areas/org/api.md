# Org API Contract

This page defines the API contract for the org area.

## Response Principles

- Always return stable ids and codes.
- Keep display fields simple (`*_name`) and avoid overloading with permission data.
- Keep assignment APIs explicit by relationship type.

## Department Endpoints

- `GET /org/department/index`
- `POST /org/department/create`
- `POST /org/department/edit`
- `POST /org/department/delete`

Required department payload fields:

- `department_id`
- `department_code`
- `department_name`
- `parent_id`
- `display_order`
- `status`

## Position Endpoints

- `GET /org/position/index`
- `POST /org/position/create`
- `POST /org/position/edit`
- `POST /org/position/delete`

## Group Endpoints

- `GET /org/group/index`
- `POST /org/group/create`
- `POST /org/group/edit`
- `POST /org/group/delete`

## Assignment Endpoints

- `GET /org/staff-department/index`
- `POST /org/staff-department/create`
- `POST /org/staff-department/delete`
- Same pattern for `staff-position` and `staff-group`.

Required assignment payload fields:

- `staff_id`
- target key (`department_id` or `position_id` or `group_id`)
- optional `is_primary` for department and position relations

## Error Handling

- Use consistent validation errors for duplicate `*_code`.
- Return clear conflict errors for duplicate assignments.
- Return explicit domain errors for hierarchy cycle checks.
- Reject mixed-subject payloads that use `user_id` or `admin_id` instead of `staff_id`.
