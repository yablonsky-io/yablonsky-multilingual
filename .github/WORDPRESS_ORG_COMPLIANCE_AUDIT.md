# YMulti WordPress.org Compliance Audit — in progress

Baseline: public immutable release `v2.53.7` / `main` at `ae38f0931559`.
Working branch: `audit/wporg-compliance-ymulti`. Primary tracking: issue #1, draft PR #2.
Source of criteria: cross-product WordPress.org compliance handoff received 2026-11-10, including `05_YMULTI_PRODUCT_HANDOFF.md` and `02_MANDATORY_RELEASE_GATE.md`.

## Gate status (as of 2026-11-10)

| Gate | Status | Evidence / remaining work |
| --- | --- | --- |
| A: provenance & identity | PARTIAL | Header/version/domain, release tree and SHA-256 source manifest checked. Installation root, runtime compatibility and byte-for-byte independently installed ZIP check still required |
| B: input, authorization, storage | IN PROGRESS | Selected REST, PHP AJAX/admin-post, SQL, media, Autopilot, jobs, XLIFF and PO flows inspected. Three findings below. Full endpoint/source-to-sink register and runtime low-role tests still required |
| C: services & directory rules | IN PROGRESS | Optional Frankfurter missing disclosure identified and draft readme amended. Exhaustive outbound inventory, terms/privacy link verification and promotional policy review still required |
| D: tests & release evidence | UNVERIFIED | No WPCS, Plugin Check, PHP 7.4 runtime, WordPress E2E, WooCommerce/multisite, XSS/IDOR role matrix or full locale verification claimed |

## Confirmed findings and draft fixes

1. **External-services disclosure**: `includes/class-ymulti-fx-rates.php` contacts Frankfurter for explicitly configured automatic rates. Prior `readme.txt` disclosed only Yablonsky AI. Draft PR #2 adds purpose, trigger, query data and provider links. Review all service references before release.
2. **Alternate image object authorization**: `MediaLocalization::save_full` previously accepted an existing image attachment ID based on MIME/type but without checking user `edit_post` on that alternate attachment. Draft PR #2 enforces per-object authorization before writing any sidecar data. Regression required for legitimate shared-media workflows.
3. **Media AI campaign ownership**: `MediaAIJobs::handle_status_ajax` previously checked generic upload/compute permissions and nonce, but not job owner; `can_control_job` treated ownerless jobs as available to eligible actors. Draft PR #2 requires owner or administrator for polling, control and admin-panel status. Verify migration/legacy ownerless scenarios.

## Inspected XSS / HTML surfaces (targeted, not comprehensive)

- `assets/admin-content.js` uses `innerHTML` for a server-generated media family panel; `MediaWorkspace::family_panel_html` escapes post titles and locale labels via `esc_html`, attributes via `esc_attr`, and editor links via `esc_url`. AJAX handler checks nonce and `edit_post`.
- `assets/admin-shell.js` REST self-test dynamic HTML uses an HTML escaping helper; server-provided raw report is assigned to `textContent`.
- `assets/ai-layout-qa.js` dynamically constructs issue labels with `escapeHtml` for text inserted into HTML. Save report verifies nonce and `edit_post`.
- `assets/language-ui.js` and `assets/admin-visual-review.js` use `textContent` for dynamically supplied labels.
- `includes/class-ypm-switcher.php` shortcode output uses context-aware `esc_html`, `esc_attr`, `esc_url`; widget output is restricted via `wp_kses`.
- `includes/class-ymulti-rest-api.php` routes declare permission callbacks; the public language discovery endpoint is intentionally public. Translation relation members are filtered by read/edit rights per target object; REST `group` is a family key rather than an array of member IDs.
- `includes/class-ymulti-string-interchange.php` PO import checks administrative capability, nonce, uploaded file/size/type and uses WordPress PO parser; translated string inputs are stored with `sanitize_textarea_field`. Full runtime/input-fuzz coverage is still outstanding.

No confirmed exploitable XSS in the paths sampled above. This must NOT be reported as plugin-wide XSS PASS. Dynamically generated HTML and translations need automated/browser tests with adversarial payloads.

## Inspected SQL / XML surfaces (targeted)

- `class-ymulti-translation-jobs.php`, `class-ymulti-autopilot.php`, `class-ymulti-translation-memory.php`, `class-ypm-string-translations.php`, `class-ymulti-string-discovery.php`: SQL values pass through WordPress `$wpdb->prepare` or typed DML and query fragments originate from fixed/internal predicates in inspected code. Database identifiers originate from the WP prefix and plugin-owned fixed suffixes. Not a global SQL PASS.
- `class-ymulti-xliff-exchange.php`: XML upload checks job access/nonce, actual file size (5 MB), extension/MIME, `is_uploaded_file`, rejects DOCTYPE/ENTITY and parses with `LIBXML_NONET`. Need malformed-XML and cross-user tests.
- `class-ymulti-media-workspace.php`: inline AI suggestion and translation-family AJAX have nonce, compute/edit rights and target-locale checks in sampled code.

## Mandatory follow-up before release

1. Enumerate *every* server entrypoint and outbound URL with source-to-sink and role evidence; test nonce/CSRF, all actors and public/private/draft/password-protected resources.
2. Audit each PHP/JS output sink, including `the_content`, translated HTML, shortcode attrs, block rendering, AJAX markup, admin notices, editor previews.
3. Verify 36 PO/MO pairs + 41 intended variants, placeholder consistency, admin/site locale switching, zh_CN/zh_TW, RTL and long-text layouts.
4. Run WPCS/Plugin Check/readme checks, PHP minimum 7.4 **runtime**, modern PHP, clean WordPress install/upgrade/uninstall, WooCommerce and interoperability.
5. Freeze new normalized release source, build a single installer, verify SHA-256 byte-for-byte across tested and published artifacts. No intermediary production ZIP.

**Acceptance rule:** unperformed tests remain UNVERIFIED. No WordPress.org PASS and no release packaging before full gate.
