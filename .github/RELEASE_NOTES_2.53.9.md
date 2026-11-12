# Yablonsky Multilingual 2.53.9

Corrective release of the accepted YMulti multilingual plugin for WordPress. No new product features or changes to the multilingual data model.

## Changes

- Restored reliable administrator UI localization for the 36 bundled language catalogs. Fixed gettext catalog metadata, including Lithuanian and French, and rebuilt the binary catalogs without rewriting translated UI content.
- Removed the unused internal Fitoterra migration adapter. WPML and Polylang remain the two supported public migration sources.
- Improved the Migration page's two-source card layout and Legacy Translation family input styling. Existing migration workflows and write confirmations remain unchanged.
- Documented the optional Frankfurter exchange-rate provider in the plugin readme.
- Preserved the established versioned asset caching strategy.

## Verification

The previous 2.53.9 QA installer passed the official WordPress Plugin Check, PHP 7.4/8.1/8.3/8.4 syntax checks, JavaScript syntax checks, and full 36-catalog gettext compilation and native WordPress loading. All 36 user-administrator locale switches also passed isolated WordPress runtime checks. The installed QA build was visually accepted by the product owner. The publisher verifies that the released ZIP matches the exact QA ZIP SHA-256.

This verification does not constitute WordPress.org directory approval or independent security certification.

## Installation

Download **yablonsky-multilingual-2.53.9.zip** from the release assets, not the automatically generated GitHub source archive. Install using **Plugins → Add Plugin → Upload Plugin** in WordPress.

**Minimum versions:** WordPress 6.0 and PHP 7.4. **Tested up to:** WordPress 7.1 as declared in the readme.

[Product overview](https://yablonsky.io/multilingual/) · [Yablonsky.io](https://yablonsky.io/) · [Yablonsky AI](https://ai.yablonsky.io/)

Versioning canon: `docs/YABLONSKY_PRODUCT_VERSIONING_CANON_v1.0.0_2026-09-21.md`.
