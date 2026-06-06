# System Area

The System area is now intentionally narrow. In the current `app-admin` slice it keeps only environment-facing
information pages plus small shared helpers that do not belong to a business area.

## Scope

- Runtime/system information views such as `/system/information/index`

## Boundaries

- Operator profile, password lifecycle, and session flows belong to the [Admin area](../admin/README.md).
- Role, permission, and menu structure belong to their own areas.
- Dict, menu, and RBAC bootstrap data now live with the areas that own them instead of one generic system option center.

## Current Shape

- `App\Areas\System\Controller\InformationController` exposes a read-only environment summary page.

Keep this area small. If a capability primarily serves one business area, move it back to that owner instead of growing
System into a catch-all admin toolbox.
