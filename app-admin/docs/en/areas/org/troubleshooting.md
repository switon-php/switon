# Org Troubleshooting

## Symptom: page keeps loading, no rows rendered

Use this checklist in order.

## 1) Verify ajax response shape

Open browser network panel and check:

- request: `GET /org/<entity>/index?...&ajax`
- response: `{"code":0,"data":{"page":...,"items":[...]}}`

If response is HTML, not JSON:

- session may be redirected to login (`401 -> 302`)
- frontend may request without ajax headers

## 2) Verify role permissions

Required permission examples:

- `org.department::index`
- `org.position::index`
- `org.group::index`
- `org.staff-department::index`

Quick SQL check:

```sql
SELECT role_code, LOCATE('org.department::index', permissions) > 0 AS has_org
FROM rbac_role
WHERE role_code IN ('superuser', 'menu', 'authenticated');
```

## 3) Verify backend route + data quickly

Use curl with session cookie:

```bash
curl -s "http://127.0.0.1:9501/org/department/index?page=1&size=15&ajax" \
  -H "Cookie: PHPSESSID=<session_id>" \
  -H "X-Requested-With: XMLHttpRequest" \
  -H "Accept: application/json"
```

If this returns rows, backend is fine and issue is frontend state/cache.

## 4) Verify frontend list contract

For list pages, keep parity with RBAC-style pages:

- controller uses `paginate(...)`
- view uses `<m-table :size="15">`
- navbar includes `keyword` field when controller supports `Keyword`

Mismatch here can cause pager/table state confusion.

## 5) Cache-related checks

Current cache behavior:

- switch state persisted in `localStorage`
- response cache stored in `sessionStorage` (`axios.cache.*`)
- turning cache off clears all `axios.cache.*`

If UI state is suspicious:

- hard refresh (`Cmd+Shift+R`)
- toggle cache off once
- reload page again

## 6) Encoding pitfall for menu labels

If inserted Chinese menu labels look garbled:

- run SQL session with `SET NAMES utf8mb4;`
- or use mysql client flag `--default-character-set=utf8mb4`

Otherwise menu cache may preserve bad values.
