# Public repository content and external communication policy

**Version:** 1.0.0  
**Date:** 2026-11-11  
**Scope:** Yablonsky.io public GitHub repositories, pull requests, issues, releases, packages and WordPress.org submissions.

## Default boundary: internal stays internal

Public repositories are for shipping product source, required licensing, user-facing documentation and neutral engineering records. Do **not** publish or attach material from private collaboration merely because it was used to identify or reproduce a bug.

Never copy into public GitHub (including issue/PR bodies, comments, workflow logs, release descriptions, screenshots, attachments or commit history):

- Private conversations, chat transcripts, prompts, informal remarks, internal project correspondence, screenshots of internal admin pages or chats, and unapproved recordings.
- Credentials, API keys, tokens, passwords, secret values, customer information, account identifiers, personal data, private URLs, private infrastructure details or unredacted operational reports.
- Internal strategy, unpublished partner information, private commercial discussions, unapproved handoff/audit packages, or raw diagnostic evidence that may reveal the above.

A user-supplied screenshot is **private by default**. Use it to diagnose an issue; publish only a concise, neutral description of the defect and fix. Never commit the screenshot or embed it in a public PR/issue unless the founder expressly approves the exact sanitized image for publication. Cropping or blurring alone is not implicit approval.

## External language: English by default

All outward-facing authored communication must be in **English** unless the founder gives an explicit instruction for a specified other language or locale. This applies to repository descriptions, README/CONTRIBUTING/SECURITY and other public prose, PR/issue titles and bodies, comments, release notes, public changelogs, WordPress.org descriptions and reviewer communications, user support responses, and social/product announcements.

Internal collaboration may use the team's chosen language; do not reproduce that internal text in public logs or documentation. Translate the **meaning** into concise professional English, rather than publishing a transcript.

**Product localization is not a public communication exception to be removed:** existing approved PO/MO catalogs, translated UI strings, locale-specific product assets and technical language identifiers remain legitimate shipped functionality. Do not rewrite or delete localized product content because this policy governs external authored communication. Explicitly commissioned multilingual public materials are also allowed.

## Pre-publication checklist (manual gate)

Before a public push, PR comment, release, package or handoff:

1. Review the exact changed files and staged release inventory, not just filenames. Ensure there are no chat screenshots, attachments, private report ZIPs, customer records or unapproved internal documents.
2. Check text, code examples, tests, fixtures, configuration and asset metadata for secrets, personal data and private endpoints; redact or omit safely. Keep necessary safe technical details only.
3. Ensure public-facing authored prose is in English unless a specific exception is authorized.
4. Confirm filenames and image/asset content are publication-approved, licensed and relevant to the shipped product. Do not upload conversation screenshots as repository documentation.
5. Keep release artifacts free of internal evidence even if the source repository uses a separate packaging manifest.
6. If uncertain whether a document, image or log is public, **do not publish it**. Request explicit approval or keep it in approved private storage.

This is a publication boundary, not a mandate to refactor accepted functionality, remove translations, rewrite historical commits, or broaden a product's WordPress.org compliance scope. Do not execute bulk source changes under this policy.

## Incidents and existing history

Removing an accidentally exposed file in a later commit does not erase it from Git history, mirrors, forks, caches or downloaded archives. If exposure is discovered, immediately stop further distribution, identify affected paths and versions, assess whether credentials/data require rotation or notifications, and coordinate appropriate history remediation. Never publicly quote sensitive material in the incident ticket itself.

**Simple rule:** Internal evidence informs our work. Only approved product material and English-by-default communication leave the team.
