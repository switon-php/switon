# Org Area FAQ

## Do we need department, position, and group together?

Yes. They represent different dimensions: ownership, responsibility, and collaboration.

## Can we launch with department only?

Yes. Keep position and group optional in early rollout, then enable them by scenario.

## Should assignment be one generic table or multiple explicit tables?

For medium and small admin systems, explicit tables are usually easier to read and maintain.

## Should we use `staff` in org schema now?

Yes. The org area uses `staff` as the subject model, including relation tables and payload keys such as `staff_id`.

## Should IDs be `BIGINT`?

Not necessarily. `INT` is usually enough for small and medium deployments.

## Where is the no-data spinner troubleshooting guide?

See [troubleshooting](troubleshooting.md).
