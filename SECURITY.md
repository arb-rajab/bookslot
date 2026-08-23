# Security Policy

## Status

This is a **private**, pre-code repository (Session 0/1 — discovery and
business framing). No application, infrastructure, or customer data exists
yet. This policy exists now so it is already in place once code and real
customer data appear, not because there is anything to report yet.

## Reporting a vulnerability

This repository is private and has no external contributors or public
issue tracker exposure. If you are an authorized collaborator and find a
security issue (in code, infrastructure config, or a dependency), email
yaeouk@gmail.com directly rather than opening a regular issue — do not
describe exploitable details in a place other collaborators or future
integrations might expose (e.g., a shared project-management tool) until
triaged.

Please include:
- A description of the vulnerability and its potential impact
- Steps to reproduce
- Affected version/commit

## Disclosure process

1. Acknowledgement within 5 business days.
2. Assessment and severity rating (informal CVSS).
3. Fix developed on a private branch.
4. No public disclosure — this is closed-source commercial software with no
   public release channel; fixes ship silently to the running product.

## Scope

Covers this repository's own code and configuration once it exists. Does
not cover third-party dependencies (report those upstream) or infrastructure
providers (Stripe, hosting, email/SMS providers) — report those directly to
the provider per their own security policy.

## A note on data sensitivity (forward-looking)

Once this product has real customers, it will process third-party personal
data (the business's own customers' names, contact details, and payment
metadata) on behalf of a business tenant. See
`docs/project-memory/06-security-threat-model.md` for the design-level
security and privacy posture, and `docs/project-memory/00-project-brief.md`
for what must never appear in this repository, in an AI session transcript,
or in any future public-facing material derived from it (real pricing, real
customer data, real Stripe Connect account structure, fraud rules,
deliverability tuning).
