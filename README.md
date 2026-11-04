# Yablonsky Multilingual

**Write and manage your website in multiple languages — with native WordPress content and optional AI assistance.**

Yablonsky Multilingual (YMulti) is a self-hosted multilingual plugin that connects translated posts, pages, products and other content while keeping editors and publication decisions under human control.

## Features

- **Languages and routing:** configure active languages, language-aware URLs, switchers and hreflang relationships.
- **Content workspaces:** connect native WordPress pages, posts, taxonomies and supported public content types into translation families.
- **WooCommerce:** multilingual products, product taxonomies, metadata and commerce email workflows.
- **Review and jobs:** translation drafts, XLIFF interchange, visual review and bounded bulk operations.
- **Autopilot:** optional administrator-configured workflow for queuing draft/review tasks with explicit scope and request limits.
- **Optional AI:** connect a user-owned Yablonsky AI account for reviewed translation suggestions. AI is not required for local translation management.

## Requirements

- WordPress 6.0 or later; PHP 7.4 or later (declared minimums).
- Plugin metadata currently declares `Tested up to: 7.1`; live acceptance shared by the project covers the earlier WordPress 6.4.2 installation. A full WordPress 7.1/PHP 7.4 runtime matrix has not been independently completed.
- Runs independently of WPML/Polylang. Coexistence and migration tools are available for sites with existing multilingual data.

## Installation

Download the versioned ZIP from GitHub Releases, upload in **WordPress → Plugins → Add Plugin → Upload Plugin**, activate and open **Yablonsky Multilingual**. Select the site's languages and configure content workflows. Back up your site before upgrading any multilingual plugin.

## Privacy and optional AI

The multilingual core works locally. Optional AI actions submit the content selected for that operation to the administrator-connected service.

- [Yablonsky AI](https://ai.yablonsky.io/)
- [Service terms](https://ai.yablonsky.io/policies/terms)
- [Privacy policy](https://ai.yablonsky.io/policies/privacy)

## Security

See [SECURITY.md](SECURITY.md) for responsible disclosure. Local static/isolated tests and live WordPress Plugin Check have been performed; no claim of an exhaustive penetration test or benchmark against competitors is made.

## License

GPLv2 or later. See [LICENSE](LICENSE).

## Origin

Developed by [Yablonsky.io](https://yablonsky.io/). YMulti is part of the Yablonsky open WordPress plugin ecosystem.
