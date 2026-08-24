# Contributing

This is a **private commercial product**, not a portfolio or open-source
project. There is no public contribution process, no public issue tracker,
and no expectation this repository is ever made public. This file exists to
set expectations for invited collaborators (contractors, future employees,
or co-founders), not for the general public.

## Before contributing

1. Read `docs/project-memory/00-project-brief.md` and
   `01-scope-and-non-goals.md` first — they are the source of truth for what
   this product is and isn't. Work against explicit non-goals will be
   declined regardless of quality.
2. Read `docs/project-memory/11-backlog.md` for planned work.
3. This repository may contain or reference real business data (pricing,
   customer records, vendor agreements) once the product has real users.
   Never paste repository content — code, docs, or data — into a
   non-approved external tool (including AI assistants) without following
   this project's sanitized-session protocol, described in
   `00-project-brief.md`. As of this writing no real data exists yet, so
   this restriction is not yet load-bearing, but it will become so the
   moment a real pilot customer or real business data appears.

## Workflow

- **Branching:** trunk-based. Branch from `main` as `feat/<slug>`,
  `fix/<slug>`, `chore/<slug>`, or `sec/<slug>`.
- **Commits:** [Conventional Commits](https://www.conventionalcommits.org/)
  (`feat:`, `fix:`, `docs:`, `test:`, `ci:`, `refactor:`, `chore:`, `sec:`).
- **Pull requests:** require review before merge to `main` once more than
  one person is working in this repository. Solo work may commit directly
  to `main` during the current discovery stage.

## Development setup

See [`README.md`](README.md#local-development) for the full local setup
(Docker Compose for Postgres/Redis, the two-role `bookslot_app`/
`bookslot_migrator` database credential split per D-0009/D-0020, and the
`composer ci:check`/`composer test:fast` scripts). The Nuxt frontend hasn't
landed yet — this is the Laravel API side only.

Before opening a PR (or committing directly, per this stage's trunk rule
above): `composer ci:check` must exit 0. It runs Pint (style), Larastan/
PHPStan (static analysis), and the fast test suite — including the
tenant-isolation suite, which is a blocking, non-negotiable part of that
gate per D-0017 (`docs/project-memory/09-decision-log.md`), not something to
work around or skip under time pressure.

## Reporting security issues

See [`SECURITY.md`](SECURITY.md).

## Confidentiality

Everything in this repository is confidential. Do not share code, docs, or
data outside people explicitly authorized by the repository owner.
