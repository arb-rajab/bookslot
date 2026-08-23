# Data Model
> Purpose: the authoritative description of stored data.
> Project: bookslot (PRIVATE track)
> Last updated: 2026-08-23 (Session 0/1 — deferred, see note)

No schema exists yet — this belongs to a future data-modeling session, done
alongside `02-requirements.md`'s full pass and `05-api-contracts.md`. This
stub records the one design direction already implied by this session's
architecture reasoning, so it isn't lost before that future session:

## Anticipated design direction (not yet implemented)

- Every tenant-scoped table (appointments, customers, services, staff, …)
  will carry a `tenant_id` (business ID) foreign key, enforced via
  application-level (ORM global scope) tenant scoping — see
  `03-architecture.md`'s multi-tenancy note and
  `06-security-threat-model.md`'s per-tenant isolation requirement.
- Appointments are anticipated to be modeled with a `tstzrange` column for
  their time window, protected by a PostgreSQL `EXCLUDE USING gist`
  constraint keyed on `(resource_id, appointment_range)` to prevent
  double-booking at the database layer — see `03-architecture.md` for the
  full reasoning and example DDL. This is a strong anticipated direction,
  not yet a finalized schema.

## Deferred to a future session

- Full ERD.
- Entity descriptions and classification.
- Indexing strategy.
- Migration approach and rollback.
- Retention and deletion rules (including the GDPR-erasure-equivalent
  deletion path noted in `06-security-threat-model.md`).
