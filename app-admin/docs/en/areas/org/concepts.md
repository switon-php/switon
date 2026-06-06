# Org Concepts

This page defines the conceptual boundary of organization data in `app-admin`.

## Core Terms

- **Department:** who a person belongs to in the formal hierarchy.
- **Position:** what function or responsibility a person has.
- **Group:** which collaboration circle a person participates in.
- **Primary assignment:** default department or position used by UI and workflow defaults.

## Current Project Terminology

- Use `staff` as the runtime subject term in org schema and org APIs.
- Keep `staff` as the product/domain term for OA-oriented usage.
- Do not mix `user_id`, `admin_id`, and `staff_id` in one org design.

## Why Three Dimensions

- Department handles ownership and hierarchy-driven policies.
- Position handles functional routing (for approvals or responsibility matching).
- Group handles temporary or project-based collaboration across departments.

Keeping all three avoids overloading one concept for every use case.

## Boundary with RBAC

- Organization data answers **who belongs where**.
- RBAC answers **who can do what**.
- The integration point is assignment mapping and policy composition, not model merging.

## Boundary with UI Customization

- Data model remains stable and complete.
- UI can choose progressive disclosure:
  - basic mode can show only department;
  - advanced mode can expose position and group fields.

This keeps the data flexible without forcing complexity on every screen.
