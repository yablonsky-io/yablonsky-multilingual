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

## 2026-11-11 — automated static and gettext audit

- Added read-only GitHub Actions workflow `.github/workflows/pre-release-static-audit.yml` (PHP lint, JS syntax, PO/MO pair and catalog parity, gettext compile/format validation). Runs on pull requests, with cancel-in-progress for superseded revisions.
- 36 PO files and 36 MO files exist. All PO catalogs and the POT have 2,054 `msgid` entries (including the header); this is structural parity only.
- Corrected `lt_LT.po` header's stale `fuzzy` flag. Corrected contradictory `#, no-python-format, python-format` flags on the literal `100% complete` string in 34 catalogs, without changing translated text. Corrected malformed concatenated PO metadata headers in 9 catalogs that caused gettext to parse an invalid charset.
- Initial CI run failed on `lt_LT` fuzzy header; next aggregate run exposed 33 format-validation failures; following pass exposed 9 invalid PO metadata headers. **Final static CI run 42915332872: SUCCESS** for PHP syntax, JS syntax and all 36 PO gettext compilation checks. Link: https://github.com/yablonsky-io/yablonsky-multilingual/actions/runs/42915332872.
- **Superseded by the later evidence below**: at this point, committed MO binaries were stale. They have since been regenerated and passed exact byte-comparison against the corrected PO sources. This does not certify independent linguistic review, RTL UI, PHP 7.4 runtime, upgrade/uninstall or WordPress role/E2E security.
- Metadata review alerts remain in 25 catalog headers (including provisional/draft labels and placeholder translator identities). Do not silently replace with false claims of native-speaker approval; route to quality review before release.

## 2026-11-11 — consolidated build, runtime and directory checks

### Verified source/build facts

- The one-time GitHub Actions job `42922974198` succeeded and generated **all 36 version-controlled MO binaries** from corrected PO files. Commit `6acf94c1cf14fac2eb3f4b828fab28b183253110` changed exactly 36 `.mo` files.
- The CI gettext gate now recompiles all PO files and compares the temporary outputs **byte-for-byte** with each checked-in MO. Run `42924769931`: PASS; all 36 match.
- The plugin previously set `YPM_ASSET_URL` to `assets/release-` plus its own version. This would have broken assets on the next version increment without copying the versioned directory. All 14 files under `assets/release-2.53.7/` were blob-identical to `assets/`. Moved runtime URL to the canonical `assets/` and deleted all 14 redundant files in one atomic commit `a577e4537d30cb91c4d155999ca2eef4a9ec7eb5`.
- Added version-independent candidate inventory `.github/PLUGIN_PACKAGE_FILES.json`, currently **167 files** including `LICENSE` and the required product-versioning canon. CI tests exact inventory equality, disallows duplicate release asset dirs, and stages only the clean product.
- Retired the completed one-time MO synchronization workflow and the obsolete hardcoded 2.53.7 release publisher workflow. Their historical successful runs/releases remain intact.
- Added normalized version checks across the plugin header, `YPM_VERSION`, WordPress.org Stable tag, changelog and versioning canon. Next corrective release coordinate remains to be assigned consistently; baseline is still `2.53.7`.

### Independently executed CI evidence

- GitHub Actions `42924769931` succeeded: PHP syntax on **7.4, 8.1, 8.3 and 8.4**, JavaScript lint, gettext compile plus byte-for-byte PO/MO agreement, official WordPress Plugin Check in a disposable WordPress environment and an isolated runtime smoke.
- WordPress Plugin Check activated the clean staged plugin and returned **No errors found**. This does not replace all manual WordPress.org review, individual role/nonce tests or full WPCS policy checks.
- Real WordPress runtime smoke loaded MO translations for `zh_CN`, `zh_TW`, `de_DE`, `ru_RU`, `ar`, `he_IL` and resolved translated string `1 row selected` correctly. It also validated plugin activation, REST route registration and runtime asset files. This is stronger than checking file counts, but is **not** an actual browser test of each admin user locale.
- Source-wide inventory read 74 PHP files and 19 JS files (including historical versioned duplicates before deletion). A repeatable CI tripwire scans the clean candidate: 73 PHP files excluding `uninstall.php`, 10 JS, 12 authenticated AJAX hooks, 86 admin-post hooks, 3 registered REST routes and 9 outbound PHP HTTP call sites. Calls are confined to known Yablonsky AI, optional Frankfurter and secure AI-generated image-download flows; no hardcoded third-party browser transport detected. This is not a proof of IDOR/XSS absence.
- Verified that Frankfurter's provider documentation and terms page exist and cover the ECB route; independently checked the Yablonsky AI terms/privacy URLs. The optional FX schedule is activated by the merchant's choice of automatic source; manual FX/local multilingual operation does not contact Frankfurter.

### Release-blocking remaining work

1. Real WordPress roles and object-level security matrix (including media alternate-image authorization, ownerless/foreign AI campaign, AI compute rights, draft/private resources, CSRF), with regression tests that prove fixes work.
2. WordPress/WooCommerce browser-level admin language switching (not merely direct MO loading), 36 locale QA with 41 intended variants, RTL, long labels, translation semantic quality. Catalog metadata in 25 languages still reflects draft or unverified editorial provenance; do not falsely mark human linguistic certification.
3. Separate comprehensive WPCS, PHP 7.4 runtime behavior (not just syntax), full install/upgrade/multisite/cron/compatibility, error logging and clean uninstall tests.
4. Assign new normalized corrective version across all release coordinates; freeze audited tree; build and verify **one** clean installable ZIP against the exact passing source revision. Do not overwrite published `v2.53.7` or merge this draft before all required gates have evidence.

**Current release decision: HOLD. Working branch only, `main` and public `v2.53.7` unchanged.**
