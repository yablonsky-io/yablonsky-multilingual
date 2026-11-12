=== Yablonsky Multilingual ===
Contributors: yablonskyio
Tags: multilingual, hreflang, seo, woocommerce, localization
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.53.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Set up your languages, translate pages and products, and manage your multilingual website in one place.

== Description ==

Yablonsky Multilingual (YMulti) is a fast, self-hosted multilingual plugin for WordPress.

Design goals:

* Minimal runtime overhead: only active languages affect routing and hreflang output.
* SEO-native content model: each language version is a separate post ID and URL.
* Public post-type support, including WooCommerce products and Easy Digital Downloads downloads.
* Local-first core: routing, editing and multilingual data run inside WordPress; optional remote AI activates through an administrator-owned Yablonsky AI connection.
* Multilingual core functionality is intended to remain fully free.
* Automatic multicurrency rates are optional, background-only and cached locally; manual merchant-owned rates remain available.

== Installation ==

1. Install the current `yablonsky-multilingual-<version>.zip` from Plugins → Add Plugin → Upload Plugin, or upload the `yablonsky-multilingual` directory to `/wp-content/plugins/`.
2. Activate the plugin.
3. Open the top-level `Yablonsky Multilingual` admin menu.
4. Choose the default language and activate the languages used by the site.
5. Edit a supported post, page, product, download, or public custom post type and use the `YMulti: Language & Translations` meta box.

== Usage ==

* Add the native `YMulti Language Switcher` block in the block editor.
* Use `[ymulti_language_switcher]` for new placements. The legacy `[ypm_language_switcher]` alias remains supported for existing sites.
* Existing classic widget installations remain supported for backward compatibility.

== Frequently Asked Questions ==

= Does this work with Yoast SEO? =

The architecture keeps each translation as a separate WordPress object and URL so SEO metadata remains independent per language. Yoast keeps authority over its own SEO internals.

= Does this work with WooCommerce and Easy Digital Downloads? =

WooCommerce is a first-class YMulti workload. Product translations use separate product objects per locale while SKU, price, stock, tax, shipping, dimensions and downloadable state synchronize across one translation family. Categories, tags, brands and global attribute terms map to real translated terms. Variable products use variation families so new and existing variations stay aligned across translated parents through batch-primed local relationships on frontend requests.

= Does YMulti send content to an external service? =

The multilingual core runs locally inside WordPress with local routing and data authority. Yablonsky AI translation activates when an administrator connects a Yablonsky AI User API key, selects a workspace and passes a live selected text-model canary. AI requests contain the content explicitly submitted for that translation action and travel to `https://ai.yablonsky.io/api`.

The API key authenticates remote compute. Local YMulti functionality remains available independently from the AI compute connection.

= How does AI publication work? =

Content translation creates a WordPress draft for human review. Taxonomy and string AI actions fill suggestions into native YMulti/WordPress forms, and a human save remains the publication gate. Existing translations stay authoritative until an approved save. Administrators with `manage_options` initiate paid AI compute by default; sites can deliberately delegate this with the `yablonsky_multilingual_ai_compute_capability` filter.

= Does YMulti paginate large admin lists? =

Yes. Translation families, Taxonomies and Strings use bounded server-side pagination. Languages, Commerce Emails and finite WooCommerce registry/entity lists use a shared client-side pager so all form fields remain in the page while only a bounded working slice is visible. AI translation drafts use a separate paginated Review Queue. Search and filters return to a valid page whenever the result set changes.

= Can YMulti plan AI translation across a larger site? =

Yes. Site Translation Plan builds a bounded local preview of translation gaps across selected active locales and public content types using local data. Starting the campaign is a separate explicit action. Execution creates review drafts, advances one task per background tick, re-checks permissions, locale state, AI canary state and existing translations before each write, and supports pause, resume, cancel and manual continuation. A bounded campaign keeps review predictable; another preview can continue after review when more content remains.

= How does Vision review work? =

Vision review starts per image and may consume workspace credits. It can report visible text in pixels, draft localized title/alt/caption/description metadata and recommend a separate localized asset. Human review and save actions keep image pixels and attachment metadata under administrator control.

== External services ==

Frankfurter exchange-rate API (https://frankfurter.dev/) is used only if a merchant selects the optional automatic exchange-rate source in YMulti's multicurrency settings. On initial enablement and while automatic rates remain selected, the site's server requests publicly available reference rates from https://api.frankfurter.dev/v2/providers/ecb/rates. The request includes configured base and target currency codes. It does not intentionally include customer records, order details, translated content, WordPress account credentials or the Yablonsky AI API key. The remote service and network intermediaries may receive standard connection metadata. Sites may keep manual rates instead; automatic fetching is not needed for the multilingual core.

Frankfurter service: https://frankfurter.dev/
Frankfurter license, provider terms and disclaimer: https://frankfurter.dev/license/
Frankfurter API privacy explanation: https://frankfurter.dev/ (FAQ: privacy policy)

Yablonsky AI is an optional external service activated by an administrator who connects a User API key and starts an AI action. For structured HTML-first content, YMulti keeps page-source authority local and sends approved human-language segments together with source/target locale, semantic role and limited structural context to `https://ai.yablonsky.io/api`; the complete editable HTML/JavaScript/CSS source stays under local YMulti control. Plain strings and taxonomy suggestions send the selected source text and translation context. Vision review sends one explicitly selected local image, target locale and localization-analysis prompt to the selected Vision model through the User conversation/message API. Vision suggestions remain advisory until an administrator applies and saves them. The User API key and selected workspace ID provide authentication and authority. Automatic text translation uses the workspace Writer route by default; an explicitly selected entitled text model uses the User conversation/message route after a fresh live canary. Local multilingual routing, editing, pagination, migrations, Layout QA and manual translations run independently from the AI service.

Service home: https://ai.yablonsky.io/
Service terms: https://ai.yablonsky.io/policies/terms
Service privacy: https://ai.yablonsky.io/policies/privacy

== Privacy ==

YMulti keeps core multilingual operation and product telemetry local to WordPress. Optional Yablonsky AI features send the content required for the administrator-requested AI action to `https://ai.yablonsky.io/api`. Structured page localization sends approved human-language segments plus source/target language, semantic role and limited context in bounded staged requests while the complete editable HTML/JavaScript/CSS source stays under local YMulti control. Plain strings and taxonomy suggestions send their selected source text and translation context. Vision media review uploads the individual local image an authorized editor chooses to analyze and stores a local advisory result containing visible-text findings, suggested alt/caption, model/cost metadata and review time. Human save actions keep media files and attachment fields under administrator control. Authentication uses the administrator-provided User API key and selected workspace ID. Sodium or OpenSSL protects the stored key when available, and the UI displays only a masked representation after saving. YMulti persists a limited account/workspace capability projection while upstream provider credentials remain with the AI service. Signed Layout QA runs in the site's own frontend preview and stores a local summary (PASS/REVIEW, issue counts, viewport and content fingerprint); detailed DOM evidence stays local. Image-generation routing remains a separate administrator-requested workflow.

== Changelog ==

= 2.53.8 =
* Restore all 36 bundled WordPress administration locale catalogs; preserve translation wording and the existing localization engine.
* Remove the unused internal Fitoterra migration source; keep WPML and Polylang migration support.
* Make the two Migration source cards compact, equal-width and clearly separated from the Legacy section.
* Clarify the optional external currency-rate provider in the readme.

= 2.53.7 =
* Audit all 36 language packs and localize Autopilot terminology consistently in navigation, messages, buttons and workflows.

= 2.53.6 =
* Localize Autopilot UI in Russian, Simplified Chinese and Traditional Chinese.

= 2.53.5 =
* Update WordPress 7.1 compatibility metadata.

= 2.53.4 =
* Localize the Installed Plugins description using the existing, reviewed 36-language UI message catalog; keep WordPress-core metadata translation native.

= 2.53.3 =
* Fix malformed MO index-table headers in all 36 bundled language packs so the WordPress POMO MO reader can import them. Add fail-closed native-format and UI translation regression gates.

= 2.53.2 =
* Keep YMulti administrator UI translations aligned with the WordPress user locale even when a multilingual integration has temporarily switched the content locale. Preserve native WordPress translation precedence and independent public-locale routing.

= 2.53.1 =
* Restore admin-language translations with a domain-scoped native-MO fallback, and address Plugin Check findings.

= 2.53.0 =
* Adds bundled gettext UI catalogs for 36 non-English WordPress locales, including independently named Simplified and Traditional Chinese packs.
* Orders YMulti-owned language selectors by documented global-language popularity without changing WordPress-owned selectors, routing identity, or saved language membership.
* Recognizes the official WordPress Maltese locale `mlt` and keeps legacy `mt_MT` input compatibility.
* Preserves the existing UI, content model, schema v8, REST v1 and multilingual relationships.


= 2.52.5 =
* Restricts REST translation graph metadata to related WordPress objects the current actor can read or edit, preserving assigned translation and review workflows.
* Keeps the /ymulti/v1 response shape, SEO graph provider, WPML bridge, Visual Review and database schema v8 unchanged.

Older release notes are preserved in changelog.txt.
