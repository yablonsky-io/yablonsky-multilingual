# Yablonsky Product Versioning Canon v1.0.0 — 2026-09-21

All Yablonsky.io product lines use `MAJOR.MINOR.PATCH`, with `MINOR` bounded to `0–99`.

Before any external candidate/package/handoff:

- `normalized_major = major + floor(minor / 100)`
- `normalized_minor = minor mod 100`
- patch is unchanged.

Examples:

- `X.100.Z = (X+1).0.Z`
- `X.115.Z = (X+1).15.Z`
- `5.100.Z = 6.0.Z`

Never publish an external version with `MINOR >= 100`.

`PATCH` is reserved for corrective releases on the same feature surface. New feature batches advance `MINOR`.

A major increment caused only by the 100-minor rollover is not automatically a breaking API change. REST `/v1`, versioned hooks `_v1`, schema contracts and stored-data contracts have independent compatibility/versioning rules.

Release gate: external candidate FAIL if plugin/app header, Stable tag, filename, release notes or handoff uses `MINOR >= 100` or inconsistent normalized versions.
