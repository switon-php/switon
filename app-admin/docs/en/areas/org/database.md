# Org Database Specification

## Final Schema

- `org_department`
- `org_position`
- `org_group`
- `org_staff_department`
- `org_staff_position`
- `org_staff_group`

## Required Fields

### `org_department`

- `department_id` (PK, `INT`)
- `department_code` (unique)
- `department_name`
- `parent_id` (NOT NULL, default `0` for root)
- `display_order`
- `status`
- `created_by`, `updated_by`, `created_at`, `updated_at`

### `org_position`

- `position_id` (PK, `INT`)
- `position_code` (unique)
- `position_name`
- `display_order`
- `status`
- `created_by`, `updated_by`, `created_at`, `updated_at`

### `org_group`

- `group_id` (PK, `INT`)
- `group_code` (unique)
- `group_name`
- `type` (NOT NULL, default empty string or `default`)
- `display_order`
- `status`
- `created_by`, `updated_by`, `created_at`, `updated_at`

### `org_staff_department`

- `id` (PK, `INT`)
- `staff_id`
- `department_id`
- `is_primary` (NOT NULL, default `0`)
- `created_at`
- unique: `(staff_id, department_id)`

### `org_staff_position`

- `id` (PK, `INT`)
- `staff_id`
- `position_id`
- `is_primary` (NOT NULL, default `0`)
- `created_at`
- unique: `(staff_id, position_id)`

### `org_staff_group`

- `id` (PK, `INT`)
- `staff_id`
- `group_id`
- `created_at`
- unique: `(staff_id, group_id)`

## Constraints

- Explicit relation tables (no polymorphic assignment table).
- Unique index on every `*_code`.
- Department hierarchy must reject cycles.
- One primary department and one primary position per `staff_id`.

## MySQL DDL

```sql
CREATE TABLE `org_department` (
  `department_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `department_code` VARCHAR(64) NOT NULL,
  `department_name` VARCHAR(64) NOT NULL,
  `parent_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `display_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_by` VARCHAR(32) NOT NULL DEFAULT '',
  `updated_by` VARCHAR(32) NOT NULL DEFAULT '',
  `created_at` INT NOT NULL DEFAULT 0,
  `updated_at` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`department_id`),
  UNIQUE KEY `uk_department_code` (`department_code`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_status_display_order` (`status`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `org_position` (
  `position_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `position_code` VARCHAR(64) NOT NULL,
  `position_name` VARCHAR(64) NOT NULL,
  `display_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_by` VARCHAR(32) NOT NULL DEFAULT '',
  `updated_by` VARCHAR(32) NOT NULL DEFAULT '',
  `created_at` INT NOT NULL DEFAULT 0,
  `updated_at` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`position_id`),
  UNIQUE KEY `uk_position_code` (`position_code`),
  KEY `idx_status_display_order` (`status`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `org_group` (
  `group_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_code` VARCHAR(64) NOT NULL,
  `group_name` VARCHAR(64) NOT NULL,
  `type` VARCHAR(32) NOT NULL DEFAULT '',
  `display_order` INT NOT NULL DEFAULT 0,
  `status` TINYINT NOT NULL DEFAULT 1,
  `created_by` VARCHAR(32) NOT NULL DEFAULT '',
  `updated_by` VARCHAR(32) NOT NULL DEFAULT '',
  `created_at` INT NOT NULL DEFAULT 0,
  `updated_at` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`group_id`),
  UNIQUE KEY `uk_group_code` (`group_code`),
  KEY `idx_status_display_order` (`status`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `org_staff_department` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `staff_id` INT UNSIGNED NOT NULL,
  `department_id` INT UNSIGNED NOT NULL,
  `is_primary` TINYINT NOT NULL DEFAULT 0,
  `created_at` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_staff_department` (`staff_id`, `department_id`),
  KEY `idx_department_id` (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `org_staff_position` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `staff_id` INT UNSIGNED NOT NULL,
  `position_id` INT UNSIGNED NOT NULL,
  `is_primary` TINYINT NOT NULL DEFAULT 0,
  `created_at` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_staff_position` (`staff_id`, `position_id`),
  KEY `idx_position_id` (`position_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `org_staff_group` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `staff_id` INT UNSIGNED NOT NULL,
  `group_id` INT UNSIGNED NOT NULL,
  `created_at` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_staff_group` (`staff_id`, `group_id`),
  KEY `idx_group_id` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
