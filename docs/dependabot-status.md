# Dependabot status

_Last updated: 2026-10-09. Maintained during the Dependabot clean-up pass; update when the state changes._

## Configuration

- Ecosystems covered: composer (`/`), github-actions (`/`), npm (`/frontend`), docker-compose (`/`).
- Grouping: none (one PR per update).
- Schedule: weekly.
- Ignore rules: `typescript` majors in `/frontend` (TypeScript 7 drops the `lib/tsc` entry point that vue-tsc/@volar rely on); `@types/node` majors in `/frontend` (the types track the Node major CI runs, now 22; raise both together); docker-compose image majors (stateful services need a deliberate migration).

## State at last update

- Open Dependabot PRs: 0 (each merged or closed only after reading its checks).
- Default-branch CI: green at last check.
- Last full rescan: 2026-10-09. Checked open PRs (none), default-branch and scheduled CI, Dependabot update jobs, ecosystem coverage (no new manifests since 2026-10-08), Actions pins, exemption expiry dates and stray branches, plus three new dimensions: branch-protection required contexts against the check runs a PR actually produces, the repo's `security_and_analysis` settings, and check-run annotations on `main`. No required context is stale. The annotations showed `ubuntu-latest` moving to Ubuntu 26 from 2026-10-19, so every job is now pinned to `ubuntu-24.04` (see Notes). The full-history gitleaks scan was not repeated: the only commits since 2026-10-08 are docs and CI changes, each scanned by the push-run gitleaks job. Rescan cycle 2 (same day, after those pins merged) repeated every dimension and added one: each repo's `SECURITY.md` and whether GitHub private vulnerability reporting is enabled.

## Time-limited exemptions

- `osv-scanner.toml`, by advisory ID (`IgnoredVulns`, `ignoreUntil` 2026-11-15): GHSA-vfj7-8cjw-p6xm (`braces` 3.0.3), GHSA-86w9-cpqp-85rv (`node-forge` 1.4.0), GHSA-858h-whjf-mvg5 / GHSA-g4wm-2vf7-vfgr / GHSA-x6jw-m9v5-85vh (`simple-git` 3.36.0), GHSA-v5rq-49vh-5v5c (`@simple-git/argv-parser` 1.1.1). All via Nuxt build/dev tooling. simple-git 4 breaks `nuxt build` (no default export) and no released Nuxt moves @nuxt/devtools off 3.x (only a DevTools 4.0.0 beta has dropped it).
- These were package-level `PackageOverrides` until 2026-10-08, which would also have hidden any new advisory against those versions. By ID, a new advisory fails the scan. Approved by the repo owner 2026-10-08 as a narrowing of the existing exemptions (same advisories, same expiry).

## Notes

- `vue-tsc` is incompatible with TypeScript 7; do not take the TS major until it is.
- Flaky Vitest/E2E steps occasionally fail on unrelated PRs; one re-run on the same commit is the accepted check.
- `require.php` is `^8.4.1`, not `^8.4`. Dependabot resolves composer updates against the lowest PHP the constraint allows, and `^8.4` meant 8.4.0, below the 8.4.1 floor of the locked Symfony 8.1 / PHPUnit 13 packages. Every composer update job failed (`dependency_file_not_resolvable`, first seen on larastan) until this was raised on 2026-10-08.
- Every workflow declares a top-level `permissions: contents: read` (added 2026-10-08, rescan cycle 3). Jobs that need more, such as CodeQL's `security-events: write`, declare it at job level.
- Merge policy (deliberate choice by the repo owner, 2026-10-08): every PR, major-version dependency bumps included, is merged as soon as all of its required checks are green, confirmed per PR. This repo is a code showcase with no business or sensitive dependency, so green checks are the only gate. Red, pending or conflicted PRs are fixed or closed instead.
- Every Linux job runs on `ubuntu-24.04` (pinned 2026-10-09; it is what `ubuntu-latest` resolved to). GitHub moves `ubuntu-latest` to Ubuntu 26 from 2026-10-19, and an unattended image change could turn every check red at once. Move to `ubuntu-26.04` deliberately, in one PR whose CI has run on it. Dependabot does not bump `runs-on` labels.
- `SECURITY.md` corrected 2026-10-09 (rescan cycle 2): it still described a private, pre-code repository with no public disclosure. It now sends reporters to GitHub private vulnerability reporting, which was disabled here; the repo owner changed it through the API on 2026-10-09 and the read-back confirmed it (`enabled: true`).
- CI runs `nuxt build` (added 2026-10-09, rescan cycle 3). Until then the only frontend build in CI was `nuxt dev` inside the E2E step, so an update that breaks only the production build (simple-git 4 is one) could pass every required check.

## Deferred (not re-raised each pass)

- Ignored major versions are listed in `.github/dependabot.yml` with the reason for each.
- Re-check exemptions before their `effectiveUntil` date (2026-11-15) and drop them once upstream fixes ship.
- Alerts read 2026-10-09 with the repo owner's PAT, run on their machine (Claude sessions still get 403: the proxy sends a GitHub App token instead of `GH_ALERTS_TOKEN`, even a PAT passed explicitly). Six open Dependabot alerts, none fixable yet, all in `frontend/package-lock.json` via Nuxt build/dev tooling and all already exempted in `osv-scanner.toml` until 2026-11-15: #5, #6, #7 `simple-git` 3.36.0 (fix 4.0.0/4.0.1), #8 `@simple-git/argv-parser` 1.1.1 (fix 2.0.1), #2 `braces` 3.0.3 and #1 `node-forge` 1.4.0 (no fix). Re-checked 2026-10-09: `simple-git` 4.0.2 still has no default export and `@nuxt/devtools` 3.4.2 still does `import Git from 'simple-git'`, so an override would break `nuxt build`. All six were dismissed on GitHub as tolerable risk on 2026-10-09, matching the exemptions; re-check them by 2026-11-15. No code-scanning alerts. Re-read 2026-10-09 after rescan cycle 2: still no open alerts.
