# Deployment and Operations
> Purpose: how this runs, and how someone else keeps it running.
> Project: bookslot (PRIVATE track)
> Last updated: 2026-08-23 (Session 0/1 — deferred, see note)

No deployable system exists yet. Deferred to a future session. One
operational concern worth flagging now, since it follows directly from
this session's architecture direction:

## Anticipated operational concern (not yet implemented)

- Reminder delivery (email/SMS) and Stripe webhook processing are both
  time-sensitive, externally-triggered background work — anticipated to run
  via Redis-backed Laravel queues (see `03-architecture.md`) with retry/
  backoff, rather than synchronously in the request cycle. Deliverability
  tuning (avoiding spam-folder placement) is real operational know-how that
  must remain private per `00-project-brief.md`'s "what must remain private
  forever" list — this document should describe *that it's handled*, never
  the specific tuning itself, even once a future session fills this file
  in for real.

## Deferred to a future session

- Environments, build/release pipeline, deployment procedure.
- Migration and rollback procedure.
- Configuration and secrets management (Stripe keys, webhook secrets).
- Observability (logs, metrics, health checks) and runbooks.
- Backup and restore procedure — critical here given real customer payment
  and appointment data will be at stake once this is live.
