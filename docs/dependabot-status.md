# Dependabot status

_Last updated: 2026-10-08. Maintained during the Dependabot clean-up pass; update when the state changes._

## Configuration

- Ecosystems covered: composer (`/`), github-actions (`/`), npm (`/frontend`).
- Grouping: none (one PR per update).
- Schedule: weekly.
- Ignore rules: `typescript` majors in `/frontend` (TypeScript 7 drops the `lib/tsc` entry point that vue-tsc/@volar rely on).

## State at last update

- Open Dependabot PRs: 0 (each merged or closed only after reading its checks).
- Default-branch CI: green at last check.

## Time-limited exemptions

- `osv-scanner.toml`: `braces` 3.0.3 (GHSA-vfj7-8cjw-p6xm), `node-forge` 1.4.0 (GHSA-86w9-cpqp-85rv), `simple-git` 3.36.0 + `@simple-git/argv-parser` 1.1.1 (GHSA-858h/-g4wm/-x6jw/-v5rq-49vh-5v5c). All via Nuxt build/dev tooling; `effectiveUntil` 2026-11-15. simple-git 4 breaks `nuxt build` (no default export) and no released Nuxt moves @nuxt/devtools off 3.x.

## Notes

- `vue-tsc` is incompatible with TypeScript 7; do not take the TS major until it is.
- Flaky Vitest/E2E steps occasionally fail on unrelated PRs; one re-run on the same commit is the accepted check.

## Deferred (not re-raised each pass)

- Ignored major versions are listed in `.github/dependabot.yml` with the reason for each.
- Re-check exemptions before their `effectiveUntil` date (2026-11-15) and drop them once upstream fixes ship.
