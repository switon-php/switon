# Audit Log Area

The Audit Log area is the read side for operator activity. It records administrative actions and login history, while the Admin area owns the login flow and workbench aggregation that feed those logs.

## Scope

- Action-log history
- Login-log history
- Personal recent-activity views used from the admin shell

## Boundaries

- Retention policy is still application-owned.
- Alerting and risk scoring are not part of this area yet.
- Login lockout, password policy, and session kicking belong to the [Admin area](../admin/README.md), not here.

## Next

- Add retention policy and query strategy docs when operations requirements are formalized.
