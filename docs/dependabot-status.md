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
- Last full rescan: 2026-10-08. Checked open PRs, default-branch and scheduled CI, Dependabot update jobs, ecosystem coverage against the manifests in the repo, Actions pins, exemption expiry dates, stray branches, and (new this pass) a local full-history gitleaks 8.28.0 scan. No new gaps. The failed composer update job (2026-10-08 18:10 UTC) predates the PHP-floor fix in #59; the next weekly run is the first to use it. The simple-git security-update jobs fail because simple-git 4 breaks `nuxt build` (see the exemptions above).

## Time-limited exemptions

- `osv-scanner.toml`, by advisory ID (`IgnoredVulns`, `ignoreUntil` 2026-11-15): GHSA-vfj7-8cjw-p6xm (`braces` 3.0.3), GHSA-86w9-cpqp-85rv (`node-forge` 1.4.0), GHSA-858h-whjf-mvg5 / GHSA-g4wm-2vf7-vfgr / GHSA-x6jw-m9v5-85vh (`simple-git` 3.36.0), GHSA-v5rq-49vh-5v5c (`@simple-git/argv-parser` 1.1.1). All via Nuxt build/dev tooling. simple-git 4 breaks `nuxt build` (no default export) and no released Nuxt moves @nuxt/devtools off 3.x (only a DevTools 4.0.0 beta has dropped it).
- These were package-level `PackageOverrides` until 2026-10-08, which would also have hidden any new advisory against those versions. By ID, a new advisory fails the scan. Approved by the repo owner 2026-10-08 as a narrowing of the existing exemptions (same advisories, same expiry).

## Notes

- `vue-tsc` is incompatible with TypeScript 7; do not take the TS major until it is.
- Flaky Vitest/E2E steps occasionally fail on unrelated PRs; one re-run on the same commit is the accepted check.
- `require.php` is `^8.4.1`, not `^8.4`. Dependabot resolves composer updates against the lowest PHP the constraint allows, and `^8.4` meant 8.4.0, below the 8.4.1 floor of the locked Symfony 8.1 / PHPUnit 13 packages. Every composer update job failed (`dependency_file_not_resolvable`, first seen on larastan) until this was raised on 2026-10-08.
- Every workflow declares a top-level `permissions: contents: read` (added 2026-10-08, rescan cycle 3). Jobs that need more, such as CodeQL's `security-events: write`, declare it at job level.
- Merge policy (deliberate choice by the repo owner, 2026-10-08): every PR, major-version dependency bumps included, is merged as soon as all of its required checks are green, confirmed per PR. This repo is a code showcase with no business or sensitive dependency, so green checks are the only gate. Red, pending or conflicted PRs are fixed or closed instead.

## Deferred (not re-raised each pass)

- Ignored major versions are listed in `.github/dependabot.yml` with the reason for each.
- Re-check exemptions before their `effectiveUntil` date (2026-11-15) and drop them once upstream fixes ship.
- Dependabot/code-scanning alert API (2026-10-08): not readable. The proxy-injected `GH_ALERTS_TOKEN` is sent, but `GET /repos/arb-rajab/*/dependabot/alerts` and `/code-scanning/alerts` return 403 "Resource not accessible by integration" on all 12 repos; the token lacks the `vulnerability_alerts` / `security_events` read permissions. Alert state remains unverified.
