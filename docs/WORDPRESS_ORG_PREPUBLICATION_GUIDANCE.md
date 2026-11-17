# Yablonsky.io — WordPress.org pre-publication guidance

**Version:** 1.1.1 · **Date:** 2026-11-18 · **Scope:** Yablonsky.io WordPress plugin repositories

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
- **Input sanitation / escaping:** Review `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, `$_SERVER`, including nested arrays. Unslash and sanitize to the expected type, validate ranges/allowlists/ownership, use `$wpdb->prepare()` when needed, and escape output according to HTML/attribute/URL/JS context. The YSEO review identified repeated uses of `filter_input(..., FILTER_UNSAFE_RAW)`; **raw collection is not sanitization**. Preserve intentional structured content using fit-for-purpose validation, not destructive blanket text sanitization.
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

### F. WordPress.org community translations (GlotPress)
- **After directory acceptance and WordPress.org SVN publication:** WordPress.org imports plugin source strings into its community GlotPress project at `https://translate.wordpress.org/projects/wp-plugins/{slug}/`. Plugin authors do not need to apply separately for the basic translation project. No need to treat its absence during an initial plugin review as a pre-publication defect.
- **Extraction contract:** use the plugin's actual slug as the single text domain, with correctly internationalized PHP strings and script-translation support where applicable. WordPress.org extracts source strings from code; a bundled POT or a GitHub translation repository is not a prerequisite for extraction.
- **Community moderation:** WordPress.org users can suggest translations. Proposed strings require approval by a locale General Translation Editor (GTE) or authorized Project Translation Editor (PTE); `Waiting` proposals are not delivered as approved language packs. For a first plugin language pack, at least 90% of stable code strings must be approved (`Current`). Requesting PTE status is a separate, optional community process; authors do not automatically control all locales.
- **Translation provenance and human review:** The WordPress Polyglots team does **not accept unreviewed machine translations**. Before proposing/importing AI-assisted strings for community approval, ensure they were reviewed by a fluent human against each locale's terminology and style guidelines. Distinguish an existing shipped catalog from WordPress.org-approved translations; do not assert that all bundled languages are community-approved. This does not trigger retroactive mass retranslation of accepted releases.
- **Protect existing shipped locales:** WordPress.org can import bundled `.mo` files on initial project import. Approved WordPress.org language packs can take precedence over packaged translations. **Do not remove bundled PO/MO catalogs, change accepted wording, or retranslate locales in a pre-publication compliance pass.** Only consider de-duplicating packages after actual import, approved packs, locale quality and administrator UI behavior have been checked for the specific product; use a separately approved release.
- **After approval (operational follow-up, not a release gate):** visit the GlotPress project and language-pack status for the actual slug, check import and locale coverage, and coordinate native-speaker review through the relevant Polyglots locale team when useful. Code updates remain GitHub-driven; WordPress.org SVN distributes the plugin; GlotPress manages translations. These three systems have distinct roles.
- **Boundary:** a project with 35+ bundled translations should not be assumed to have 35+ approved community language packs. Translation adoption and language-pack size optimizations are future distribution decisions, not authorization to alter an accepted plugin while its WordPress.org review is pending.

### G. Component boundary reliability: optional, evidence-led checks
These are general engineering questions from reviewing WordPress plugin implementation patterns, **not universal WordPress.org policy violations or automatic blockers**. Only review a topic when the component and a grounded failure mode actually exist in the plugin under consideration.
- **File ownership and cleanup:** Before replacing, unlinking or rewriting a public root-level artifact such as `llms.txt`, robots rules, sitemap files or generated exports, verify whether this plugin owns that specific artifact and preserve an existing file owned by an administrator or another plugin. Exercise create/disable/uninstall flows in an isolated fixture before changing established file behavior.
- **Missing-option and external-data consent:** For optional AI/content transfer, verify that the behavior when an option is absent matches the accepted consent policy and public disclosure, not merely the option's activation-time value. A change to an accepted opt-in or privacy default requires explicit product/security approval and targeted regression tests.
- **Remote services under failure:** Where the product already calls AI/translation/market APIs, check representative HTTP timeouts, response-size bounds, error handling, key confidentiality and retry semantics, preserving approved API contracts. Do not introduce an external dependency or reroute an established provider as part of documentation or a WordPress.org-only pass.
- **Hot-path database and memory work:** Where a component processes large sitemaps, commerce feeds, 404 traffic or queues, look for demonstrable unbounded scans, repeated writes, stale locks and recovery after interrupted jobs. Test on representative volumes before claiming an optimization need; performance concerns alone do not authorize cache, SQL, scheduler or schema changes.
- **Safety classification:** For any such observation, record the exact source line or isolated reproduction, impact and existing safeguard in the finding template below. Classify as `NEEDS_CONFIRMATION` or `FUTURE_IMPROVEMENT` by default. Upgrade to `REQUIRED_FOR_PUBLICATION` only when applicable official guidance, an actual reviewer requirement, or an independently verified release-blocking security/correctness issue justifies it. **No bulk audits, refactors, automatic tests or release blocks arise from this section.**

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
- WordPress.org translation import and language packs: https://make.wordpress.org/meta/handbook/tutorials-guides/translations/
- WordPress Polyglots guide for plugin/theme authors: https://make.wordpress.org/polyglots/handbook/plugin-theme-authors-guide/
- WordPress Polyglots translation review and PTE process: https://make.wordpress.org/polyglots/handbook/plugin-theme-authors-guide/pte-request/
- WordPress plugin internationalization guidance: https://developer.wordpress.org/plugins/internationalization/how-to-internationalize-your-plugin/
- WordPress.org review case: private YSEO review email dated 2026-11-08. **This guide summarizes lessons; the original reviewer email is not copied into the public repository.**

**Simple rule:** Build correctly the first time. Preserve accepted functionality. Correct only evidenced WordPress.org publication issues, with proportionate tests and clear human approval for behavioral changes.
