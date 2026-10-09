# Security Policy

## Status

This repository is public and holds the application code. There are no
published releases; only the latest commit on `main` receives security
fixes.

## Reporting a vulnerability

Please **do not** open a public GitHub issue for security vulnerabilities.

Instead, use GitHub's private vulnerability reporting (Security tab →
"Report a vulnerability"), or email yaeouk@gmail.com directly.

Please include:
- A description of the vulnerability and its potential impact
- Steps to reproduce
- Affected version/commit

## Disclosure process

1. Acknowledgement within 5 business days.
2. Assessment and severity rating (informal CVSS).
3. Fix developed on a private branch.
4. Coordinated disclosure once a fix is merged, with credit to the reporter
   unless anonymity is requested.

## Scope

Covers this repository's own code and configuration. Does
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
