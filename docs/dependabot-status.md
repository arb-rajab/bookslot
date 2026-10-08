# Dependabot status

_Last updated: 2026-10-08. Maintained during the Dependabot clean-up pass; update when the state changes._

## Configuration

- Ecosystems covered: composer (`/`), github-actions (`/`), npm (`/frontend`), docker-compose (`/`).
- Grouping: none (one PR per update).
- Schedule: weekly.
- Ignore rules: `typescript` majors in `/frontend` (TypeScript 7 drops the `lib/tsc` entry point that vue-tsc/@volar rely on); `@types/node` majors in `/frontend` (the types track the Node major CI runs, now 22; raise both together); docker-compose image majors (stateful services need a deliberate migration).

## State at last update

- Open Dependabot PRs: 0 (each merged or closed only after reading its checks).
- Default-branch CI: green at last check.

## Time-limited exemptions

- `osv-scanner.toml`, by advisory ID (`IgnoredVulns`, `ignoreUntil` 2026-11-15): GHSA-vfj7-8cjw-p6xm (`braces` 3.0.3), GHSA-86w9-cpqp-85rv (`node-forge` 1.4.0), GHSA-858h-whjf-mvg5 / GHSA-g4wm-2vf7-vfgr / GHSA-x6jw-m9v5-85vh (`simple-git` 3.36.0), GHSA-v5rq-49vh-5v5c (`@simple-git/argv-parser` 1.1.1). All via Nuxt build/dev tooling. simple-git 4 breaks `nuxt build` (no default export) and no released Nuxt moves @nuxt/devtools off 3.x (only a DevTools 4.0.0 beta has dropped it).
- These were package-level `PackageOverrides` until 2026-10-08, which would also have hidden any new advisory against those versions. By ID, a new advisory fails the scan. Approved by the repo owner 2026-10-08 as a narrowing of the existing exemptions (same advisories, same expiry).

## Notes

- `vue-tsc` is incompatible with TypeScript 7; do not take the TS major until it is.
- Flaky Vitest/E2E steps occasionally fail on unrelated PRs; one re-run on the same commit is the accepted check.

## Deferred (not re-raised each pass)

- Ignored major versions are listed in `.github/dependabot.yml` with the reason for each.
- Re-check exemptions before their `effectiveUntil` date (2026-11-15) and drop them once upstream fixes ship.
