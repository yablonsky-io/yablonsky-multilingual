# Repository agent guidance — WordPress.org

Before making WordPress-plugin code, documentation, or release changes, read:
**[docs/WORDPRESS_ORG_PREPUBLICATION_GUIDANCE.md](docs/WORDPRESS_ORG_PREPUBLICATION_GUIDANCE.md)**.

This is **advisory** guidance for avoiding repeat WordPress.org review findings. Apply relevant guidance when developing new features and preparing a plugin for publication.

**Accepted functionality is the baseline.** A request to prepare for WordPress.org is *not* permission to refactor, alter runtime behavior, revise integration contracts, change translations, or widen the security/performance audit. Do not silently fix unrelated findings. Verify relevance, cite the actual rule, propose a minimal patch, and get explicit product approval before any behavioral change.

Do not invent CI release blockers or misrepresent advisory checks as executed tests. Product-specific decisions and explicit user instructions take precedence over optional suggestions in this guide. Continue approved feature development normally.

No automatic build, test, publishing, migration, or code modification is activated by this document.
