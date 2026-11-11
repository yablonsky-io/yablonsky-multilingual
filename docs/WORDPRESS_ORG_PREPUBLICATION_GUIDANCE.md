# Yablonsky.io — WordPress.org pre-publication guidance

**Version:** 1.0.0 · **Date:** 2026-11-11 · **Scope:** Yablonsky.io WordPress plugin repositories

> **Advisory engineering reference, not an instruction to refactor a product.** This file documents the common concerns seen in the YSEO WordPress.org review on 2026-11-08 and in official WordPress Plugin Directory guidance. Read it while building a feature, at the start of a publication-preparation task, and before a release candidate. Do **not** interpret reading the guidance as authorization to change functional code.

## 1. How to use this guide

1. **During ordinary feature development:** consult the relevant topic before introducing new admin actions, REST/AJAX endpoints, translations, remote API calls, storage keys, or WordPress.org-facing metadata. Follow applicable WordPress requirements as you write new code; do not revisit unrelated existing features.
2. **When a product is feature-accepted:** treat behavior, contracts, compatibility, existing data, and UX as the accepted baseline. WordPress.org preparation is a *narrow compliance review* of that baseline, not a new architecture, security-hardening, optimization, or localization project.
3. **During pre-publication review:** record a finding only with a file/line or reproducible case, the actual WordPress rule or reviewer feedback, its impact, and the minimum remedy. Classify it as `REQUIRED_FOR_PUBLICATION`, `NEEDS_CONFIRMATION`, or `FUTURE_IMPROVEMENT`.
4. **Only implement authorized changes.** Correct isolated, documented publication defects with the smallest possible edit and relevant regression test. If a proposed fix changes runtime behavior, storage, APIs, routing, caching, translations, migration, payment, or integration semantics, **stop and ask for explicit product approval** rather than silently incorporating it into compliance work.
5. **Don't confuse evidence:** Plugin Check, WPCS, PHP syntax, and a manual review each assess different things. Never describe a release as approved solely because one checker is green. Avoid claiming a review finding applies to another plugin without verifying it.
6. This guide is **not** a CI rule, release block, bulk-edit script, migration plan, or a blanket prohibition on normal approved development.

## 2. First-hand lessons from the YSEO WordPress.org review

Source: the WordPress.org Plugins Team's **“Review in Progress: Yablonsky SEO — GEO & AEO”**, received **2026-11-08**. That email was a review of `yablonsky-seo-5.86.1.zip`. The review warns that examples are samples, not necessarily the complete occurrence list, and that parts of the feedback can be automated/AI-assisted. **Check the actual context; do not indiscriminately replace every matching call.**

- **Nonce + capability:** For privileged actions, check request authenticity and user permissions before processing. Check `admin_post_*`, `wp_ajax_*`, REST permissions, data mutations, file operations, and sensitive reads. A nonce is not an authorization check; use an appropriate `current_user_can()` capability and object-level permission where relevant.
- **Input sanitation / escaping:** Review `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, `$_SERVER`, including nested arrays. Unsplash and sanitize to the expected type, validate ranges/allowlists/ownership, use `$wpdb->prepare()` when needed, and escape output according to HTML/attribute/URL/JS context. The YSEO review identified repeated uses of `filter_input(..., FILTER_UNSAFE_RAW)`; **raw collection is not sanitization**. Preserve intentional structured content using fit-for-purpose validation, not destructive blanket text sanitization.
- **Prefix collision prevention:** Use distinctive product-prefixed functions, globals, constants, hooks, option/transient/meta keys, shortcodes, handles and other registrations; properly namespaced classes alone do not solve all registration/storage collisions. The reviewer advised prefixes of at least four characters.
- **Public links and admin notices:** No unsolicited public-site credit/backlinks; require an explicit opt-in for public-facing attribution. Keep admin notices relevant, scoped, and dismissible as required. Review the real UI behavior before changing it.
- **Review scope and evidence:** Apply the pattern to analogous call sites, not merely the sample lines. Identify false positives and ask the reviewer when an interpretation remains unclear, instead of inventing broad functionality changes.

## 3. Practical WordPress.org topics worth checking early

### A. Plugin identity and packaging
- Choose a distinct, stable plugin name and directory slug. Check brand/trademark and naming restrictions **before submission**. Keep the main PHP header, `readme.txt`, text domain, package folder and release coordinates consistent.
- Use the product's canonical `MAJOR.MINOR.PATCH` scheme, with **MINOR 0–99**. Normalize rollover before external handoff. Keep `YABLONSKY_PRODUCT_VERSIONING_CANON_v1.0.0_2026-09-21.md` in or referenced by release handoffs. This does not imply changes to API contract versions.
- Verify GPL compatibility of code and bundled assets, identify third-party licensing, and include appropriate license text/metadata. Verify that referenced assets actually exist in the shipped package; check filename case and generated versioned filenames.
- The **WordPress.org submission FAQ currently calls for a production-ready plugin ZIP under 10 MB**. Remove *unnecessary* development files from the distribution artifact without deleting working product dependencies. If the genuine release is over the submission limit, investigate packaging and contact the reviewer; never strip runtime assets blindly.
- WordPress.org SVN is a **release distribution channel**, not an active development branch. Keep the GitHub development workflow separate from a deliberate SVN publication.
- `readme.txt` should be human-readable, not keyword stuffed, use at most five meaningful tags, and describe only delivered functionality. A claimed “Tested up to” version should correspond to actual testing, not an aspirational future version.

### B. Auth, inputs, output and data safety
- For admin operations, verify a suitable nonce **and** capability. For REST routes, define and verify a meaningful `permission_callback` appropriate to the endpoint; public routes can be intentionally public but must be designed as such.
- Check permissions on the **specific object** being edited or read, not only general administrator access. Watch for IDOR, CSRF, untrusted nested inputs and exposure of user profile data.
- Sanitize based on semantic type and validate before storage or execution; escape as late as possible when rendering HTML, attributes, JavaScript and URLs. Validate shortcode, dynamic block and REST outputs as appropriate.
- Use WordPress APIs (including Filesystem API where applicable), nonces and capabilities without replacing accepted business logic. An automated scan may flag intentional APIs; confirm the actual risk before adjusting them.

### C. External services, privacy and assets
- Document each external provider that the plugin actually calls: **operator, purpose, conditions/trigger, relevant data transmitted, terms and privacy links**. Identify whether an action is opt-in/user-triggered, and describe that accurately.
- In particular, review external market data, translation/model APIs, currency APIs, Cloudflare, telemetry and auxiliary lookup endpoints. The cross-product review of YMulti identified a **Frankfurter ECB exchange-rate API disclosure gap** as an example; this is a documentation prompt, *not* a request to rewrite the API client.
- Respect consent/authorization requirements for external communication. Don't secretly track users, load unapproved executable code, or bundle remote non-service scripts as an unintended dependency.
- Audit bundled library licenses and WordPress core-provided library duplication before publication. Avoid unnecessary remote fonts/assets.

### D. Compatibility and user experience
- Verify intended PHP and WordPress versions in a representative installation, activation, upgrade, deactivation and uninstall flow; ensure no unexpected output or fatal errors.
- Check existing WooCommerce/multilingual/multisite/RTL paths **only where applicable** and according to the product's already accepted regression matrix.
- Check that administrator locale switching works with shipped PO/MO catalogs, but do not silently retranslate or revise accepted catalogs as part of a WordPress.org-only task.
- Preserve existing feature behavior; compliance work should not mutate production settings, execute migrations, replace cache ownership, or change integrations without authorization.

### E. Tools and the release review
- Run PHP syntax checks, WPCS/PHPCS, WordPress Plugin Check and targeted manual tests against the **actual prospective distribution package**, not merely a development checkout.
- Distinguish blocker vs warning vs false positive. Keep exact evidence, rule, line, patch and test result. Do not broadly apply `--fix` or mass cleanup to a proven codebase.
- Avoid unnecessary temporary release ZIPs. Publish one consolidated external candidate only after verified acceptance and consistent naming/version metadata.
- After an official WordPress.org review, address the whole **proven** set of findings in a cohesive response and reply in the **same existing email thread**; do not submit repeated partial fixes without reason.

## 4. Minimal finding / decision record

```text
Product + accepted baseline:
Source / requirement (URL or reviewer quote):
Finding (file:line, exact reproducible behavior):
Classification: REQUIRED_FOR_PUBLICATION | NEEDS_CONFIRMATION | FUTURE_IMPROVEMENT
Effect on accepted functionality: NONE | POSSIBLE | YES
Minimal proposed remedy:
Regression evidence needed:
Decision: DOCUMENT | ASK | PATCH (explicitly approved when behavior changes)
```

A `FUTURE_IMPROVEMENT` stays in the backlog and does not expand the current publication work.

## 5. Where to stop

**Stop and obtain explicit approval** before changing algorithms, cache behavior, migrations, DB schemas, AI/API routing, integration contracts, localization engine behavior, user-facing workflows, billing, or previously accepted UX. A speculative security improvement is not automatically an urgent WordPress.org publication blocker. A verified exploitable security issue should be clearly escalated, with evidence and a narrow corrective plan; do not conceal it and do not unilaterally rewrite unrelated modules.

Keep development open and human-governed. Guidance should prevent repeat review cycles *without creating new product regressions*.

## 6. Canonical sources

- WordPress Plugin Directory guidelines: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- WordPress.org Plugin Developer FAQ (submission/packaging/review): https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/
- WordPress Plugin Check: https://wordpress.org/plugins/plugin-check/
- WordPress plugin security: https://developer.wordpress.org/plugins/security/
- WordPress best practices and namespace/prefix guidance: https://developer.wordpress.org/plugins/plugin-basics/best-practices/
- WordPress.org review case: private YSEO review email dated 2026-11-08. **This guide summarizes lessons; the original reviewer email is not copied into the public repository.**

**Simple rule:** Build correctly the first time. Preserve accepted functionality. Correct only evidenced WordPress.org publication issues, with proportionate tests and clear human approval for behavioral changes.
