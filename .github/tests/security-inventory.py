#!/usr/bin/env python3
"""YMulti WP.org source-only security inventory; no HTTP probes or code mutation.

This is a fail-closed regression tripwire for public entry points and external
transport; it is not a replacement for role-based or adversarial runtime tests.
"""
from pathlib import Path
import re
import sys

roots = [Path("yablonsky-multilingual.php")]
roots += sorted(Path("includes").rglob("*.php"))
js_paths = sorted(Path("assets").glob("*.js"))
js_paths += sorted(Path("blocks").rglob("*.js"))

allowed_transports = {
    "includes/class-ymulti-fx-rates.php",
    "includes/class-ypm-ai-translation.php",
    "includes/class-ypm-media-localization.php",
}
problems = []
ajax_handlers = []
admin_handlers = []
rest_routes = []
remote_calls = []
for path in roots:
    source = path.read_text(encoding="utf-8")
    name = path.as_posix()
    for match in re.finditer(r"add_action\s*\(\s*['\"](wp_ajax_nopriv_|admin_post_nopriv_)[^'\"]*", source):
        problems.append(f"{name}: unexpected unauthenticated endpoint: {match.group(0)}")
    for match in re.finditer(r"add_action\s*\(\s*['\"](wp_ajax_[^'\"]+)", source):
        ajax_handlers.append((name, match.group(1)))
    for match in re.finditer(r"add_action\s*\(\s*['\"](admin_post_[^'\"]+)", source):
        admin_handlers.append((name, match.group(1)))
    routes = len(re.findall(r"\bregister_rest_route\s*\(", source))
    perms = len(re.findall(r"['\"]permission_callback['\"]\s*=>", source))
    if routes != perms:
        problems.append(f"{name}: REST route declarations={routes}, permission callbacks={perms}")
    if routes:
        rest_routes.append((name, routes))
    for match in re.finditer(r"\bwp_(?:safe_)?remote_(?:get|post|request)\s*\(", source):
        remote_calls.append((name, match.group(0)))
        if name not in allowed_transports:
            problems.append(f"{name}: new external HTTP call requires disclosure and review")
    if re.search(r"\b(eval|create_function)\s*\(", source):
        problems.append(f"{name}: dynamic code execution API requires manual security exception")
    if re.search(r"\b(?:unserialize|maybe_unserialize)\s*\(\s*\$_", source):
        problems.append(f"{name}: deserialization of raw request data")
if not any(path == "includes/class-ymulti-rest-api.php" and count == 3 for path, count in rest_routes):
    problems.append("Expected three declared REST endpoints in the standard REST API")
if not remote_calls:
    problems.append("No outbound request inventory could be generated (unexpected source layout)")
readme = Path("readme.txt").read_text(encoding="utf-8")
for disclosure in (
    "== External services ==",
    "Frankfurter",
    "https://frankfurter.dev/license/",
    "https://ai.yablonsky.io/policies/terms",
    "https://ai.yablonsky.io/policies/privacy",
):
    if disclosure not in readme:
        problems.append(f"readme.txt: missing disclosure: {disclosure}")
for path in js_paths:
    content = path.read_text(encoding="utf-8")
    if re.search(r"https?://(?!www\.w3\.org)", content):
        problems.append(f"{path}: browser outbound hard-coded URL requires review")
print(f"Audited PHP files: {len(roots)}; JS files: {len(js_paths)}")
print(f"Authenticated AJAX hooks: {len(ajax_handlers)}; admin_post hooks: {len(admin_handlers)}")
print(f"REST routes: {sum(n for _, n in rest_routes)}; outbound HTTP call sites: {len(remote_calls)}")
for path, call in remote_calls:
    print(f"  Transport: {path}: {call}")
for problem in problems:
    print("FAIL:", problem, file=sys.stderr)
if problems:
    sys.exit(1)
print("PASS: static endpoint/remote-transport inventory; runtime authorization remains independently gated")
