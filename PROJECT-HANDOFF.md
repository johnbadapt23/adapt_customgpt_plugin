# CustomGPT Chat Widget — Project Handoff

Notes for picking this project back up in a fresh session (different machine, different Claude account, no memory of prior conversations). Written from the working state as of the most recent session. **If you're a Claude session reading this cold: read this whole file before touching the code — it explains several non-obvious, hard-won fixes that are easy to accidentally regress.**

## What this is

A self-hosted WordPress plugin (`customgpt-chat-widget`) that renders the CustomGPT.ai starter-kit chat widget via a `[customgpt_chat]` shortcode. It embeds directly into the page DOM (no iframe), proxies API calls server-side so the API key never reaches the browser, and ships its own compiled widget bundle under `dist/widget/` rather than pulling from a CDN.

- **Repo:** `https://github.com/johnbadapt23/adapt_customgpt_plugin` (public, `main` branch)
- **Local working copy:** `F:\WORK\git_repo`
- **Live site (staging):** `https://researchstaging1.adapt.com.au/` — this is where all testing happens
- **Production:** exists on the same server, separate WP instance, not routinely re-verified against every staging fix
- **Single canonical plugin file:** `customgpt-chat-widget.php` (everything except the fast-proxy accelerator and the compiled bundle lives in this one file)
- **Current version as of last edit: `2.19.9`** — check `grep -n "Version:" customgpt-chat-widget.php | head -1` and `git log --oneline -1` to confirm this is still current and actually pushed/tagged.

Updates ship via a GitHub-based Plugin Update Checker (PUC) baked into the plugin — sites check the repo automatically (~every 12h, or on-demand via "Check for updates" on the Plugins screen) and see a normal WP update notice when a new version is tagged. **A `git push` alone does NOT update the live site** — WordPress's own update mechanism has to separately pick it up (or the user updates manually), and page/CDN caching can also serve stale JS/CSS even after the plugin file itself updates. If a just-shipped fix doesn't appear live immediately, that's the first thing to suspect, not that the fix is wrong — always re-verify the actual deployed `<style>`/`<script>` content live before assuming a fix failed (see Diagnostic methodology below).

## Architecture

### Two-stage render: SSR placeholder → real widget

`render_hero_placeholder_html()` prints static HTML (heading, tagline, input shell, 3 question chips) directly into the page's initial HTML for LCP — this paints before the ~2MB JS bundle downloads/executes. The real widget bundle (`dist/widget/customgpt-widget.b16.min.js` + `vendors.js` + chunk files + CSS) replaces this element's contents outright once it mounts; there's no hydration. The SSR placeholder's CSS is hand-matched to the real widget's computed styles (spacing, fonts, colors, mobile breakpoints) to minimize layout shift when the swap happens — verified via live `getComputedStyle()` comparison, not guesswork, and should be re-verified the same way if the real widget's own styling ever changes.

### The proxy

All CustomGPT API calls go through `admin-ajax.php?action=customgpt_proxy&path=...` (`handle_proxy()` in the main plugin file), which holds the real API key server-side and streams SSE responses through. It's a fully generic passthrough — reads `$_GET['path']`, builds the upstream URL, forwards via curl with the original method and body — so it needed zero changes to support new upstream endpoints/methods as they came up (e.g. the message-feedback PUT below).

### Fast-path proxy accelerator (`includes/customgpt-fast-proxy.php`)

A normal `admin-ajax.php` request pays real WordPress bootstrap overhead before this plugin's own code even runs. This file is copied into `wp-content/mu-plugins/` on plugin activation (must-use plugins load *before* regular plugins/theme), and intercepts just two endpoints — `POST /conversations` and `POST /messages` — before that overhead is paid. Every other request, and either of these two if anything looks even slightly off, falls straight through to the normal `handle_proxy()` path. Toggle: Settings → CustomGPT Chat Widget → Fast Proxy. Responses that went through it carry `X-CGPT-Fast-Proxy: 1`.

Self-install/sync: `register_activation_hook`/`register_deactivation_hook` install/remove it; `admin_init` also checks `filemtime()` and re-syncs if the source is newer than the installed copy, since activation hooks don't fire on version updates.

### Per-role visibility (`customgpt_widget_current_user_can_see_widget()`)

A global function (mirrors the existing `customgpt_widget_get_external_id()` pattern) reading the `customgpt_widget_visible_roles` option, so both the plugin's own shortcode AND an external theme script that independently gates the floating widget (lives outside this repo, in the user's theme files — not found by grepping this repo) agree on who can see the widget, controlled from one settings page (Settings → CustomGPT Chat Widget → Who Can See The Widget). The theme script checks `function_exists()` first and falls back to its old hardcoded `agent_tester`-role check if the plugin isn't active.

### Message feedback (thumbs up/down) — patched entirely client-side

The compiled widget bundle's own `updateMessageFeedback` Zustand store action is **missing an implementation entirely** — confirmed by grepping the whole bundle for `message-response-feedback`: zero occurrences in any send path, only in read paths that display previously-saved feedback. Clicking thumbs silently threw before any network request fired.

Fixed via `enqueue_feedback_patch_behavior()`, which polls for `window.__customgpt_widget_stores[sessionId]` and replaces `messageStore`'s `updateMessageFeedback` action with a real implementation via `store.setState(...)`. Key things this had to get right, each one a real bug that shipped and got fixed:
- The real numeric CustomGPT prompt ID is **not reliably present** on a just-streamed message's local object (`message.details.prompt_id` is often empty, `message.id` is a client-generated string with no numeric prefix for live-session messages) — falls back to GETting the real conversation message list and matching by exact reply text, or most-recent reply if no exact match.
- The correct endpoint is the **documented, API-key-authenticated** one: `PUT /api/v1/projects/{id}/conversations/{sessionId}/messages/{promptId}/feedback` with body `{"reaction": "liked"|"disliked"}`. The floating widget's own official `chat.js` (from CustomGPT's CDN) uses a *different*, unversioned route (`/projects/{id}/prompts/{promptId}/message-response-feedback`, no `/api/v1/`) that's read-only under the versioned API (`GET, HEAD` only — confirmed via the actual 405 error) and is almost certainly session-cookie-authenticated via the CustomGPT dashboard, not part of the integrator-facing API. Copying the floating widget's URL shape was the wrong move the first time around; the real spec came from CustomGPT's own starter-kit OpenAPI doc on GitHub.

Confirmed working live by the user (network tab shows the request firing, response `isLiked`/`isDisliked` updates correctly).

### Feedback row visibility + contrast (CSS-only, `wp_footer`-injected)

The real widget's per-message feedback row is `opacity-0 group-hover:opacity-100` — invisible until hover, easy to miss entirely (especially touch devices). `enqueue_feedback_visibility_style()` forces it permanently visible with a light pink chip background and a "Was this helpful?" label (`::before` content). A follow-up pass (`enqueue_feedback_visibility_style()`, same method, several iterations) gave the 3 buttons (copy/thumbs-up/thumbs-down) a white circular chip with a visible border and distinct hover colors (green/red/charcoal) — plain grey icons on the pink background had too little contrast to read as clickable at a glance, confirmed by direct user report with a screenshot even after the row itself became visible.

**Known fragile detail:** the buttons' accessible label lives in a `title` attribute in the currently-deployed widget build, not `aria-label` as originally assumed — both selectors are kept in the CSS for safety against a future widget rebuild reverting that.

**Mobile wrap gotcha:** at narrow widths the row's 4 flex items (label + 3 buttons) wrap *individually* by default — the 3rd button can break onto its own line separated from the other two. Fixed by giving the label `flex-basis: 100%` at the existing `639.98px` breakpoint, forcing it onto its own line and pushing all 3 buttons onto the next line as a group. Verified live at 375px and 320px.

### Markdown table responsiveness (CSS-only, `wp_footer`-injected, `enqueue_table_responsive_style()`)

Two sequential attempts, both live-tested, the first one shipped and then reported broken:
1. **First attempt (reverted):** `table-layout: fixed` at 100% width, splitting columns evenly so text wraps instead of overflowing. This stopped tables' right-hand columns being cut off (the actual original bug — `table-layout: auto` sizes to content regardless of container, and the message bubble clips overflow with `overflow: hidden` rather than scrolling). But for a realistic 3–4 column comparison table in a ~380px bubble, forcing even columns only gave each ~100–125px, so cell text wrapped to one or two words per line and the table's height exploded (confirmed live: 2291px for one real response). Traded a horizontal problem for a worse vertical one — correctly called out by the user as still broken.
2. **Current approach:** standard responsive-table pattern — `table-layout: auto` with a 170px per-cell `min-width` (columns size naturally, comfortably), and the table itself becomes a horizontally scrollable box (`display: block` + `overflow-x: auto`, visible thin scrollbar) when its natural width exceeds the bubble, instead of being clipped or squeezed. Verified live: height dropped from 2291px to ~1050px on the same real table, horizontal scroll correctly reaches hidden columns (`scrollLeft` clamps exactly at `scrollWidth - clientWidth`), same result holds at mobile width.

**Honest open trade-off:** a genuinely long response (the AI sometimes returns an 18-row table rather than a tidy 3–4 row one) will still render tall no matter the CSS — that's response-length, not a layout bug. Wide tables (4+ columns) do require a sideways scroll to see every column, which is standard for data tables but worth knowing about if it comes up again.

### Citation "View source" link rewriting + media-library filename matching

`proxy_citation_request()` intercepts a citation-detail fetch and, if the citation's `title` matches a file in this site's WP media library, rewrites the citation's `url` to the local public file (CustomGPT's own citation preview URL is account-gated and 403s for anonymous visitors) via `find_local_media_url_by_filename()` → `find_attachment_url_by_exact_filename()` (matches the real `_wp_attached_file` postmeta value at a proper path boundary, not post title, not a loose substring).

**Always an exact match against a small, well-defined set of candidate filename strings — never fuzzy.** As of 2.19.9 those candidates are: the raw CustomGPT title as-is, a `sanitize_file_name()`'d version of it (WordPress's own upload-time normalization: spaces/punctuation → hyphens, accents stripped), and a lowercased copy of that — each tried against the original extension plus `pdf`/`pptx`/`ppt`/`docx`/`doc` as fallbacks. This exists because CustomGPT's citation title is the document's own title as uploaded there (often still human-readable, e.g. `"Sector Outlook Healthcare 2026.pdf"`), which commonly differs from what WordPress's sanitizer produced for the actual stored filename (e.g. `sector-outlook-healthcare-2026.pdf`) — two different systems, two different "filenames" for the same file. Non-PDF matches (pptx/docx/etc.) get routed through Microsoft's public Office Online viewer (`view.officeapps.live.com`) since those don't render natively in a browser tab the way a PDF does; requires the file to be reachable from the public internet (won't work behind an IP allowlist/Basic Auth).

**Not yet re-verified live after shipping** — the fix (sanitized-filename matching) was written, balance-checked, committed, and pushed, but the specific citation that prompted it (`"Sector Outlook Healthcare 2026.pdf"` → `sector-outlook-healthcare-2026.pdf`) has not yet been re-tested against the live deployed site. Worth doing first if this area comes up again.

### Diagnostic methodology used throughout

No `php` binary in the sandbox — PHP edits are verified with a Python brace/paren/bracket-balance count via bash (`.count('(')` vs `.count(')')` etc.), **not** a real syntax check. This only catches gross mismatches (missing/extra bracket), not logic errors — read the diff carefully regardless.

For anything CSS/JS/runtime, **always verify live in the browser rather than reasoning about compiled Tailwind/React output from first principles** — this session's single biggest recurring lesson. Concretely: use the browser tools to `getComputedStyle()` the real rendered element, check actual class names / DOM structure / attribute names (e.g. the `title` vs `aria-label` surprise above) directly rather than assuming, and when prototyping a CSS fix, inject a test `<style>` tag via `javascript_tool` into the *live* page first and confirm visually before writing it into the plugin file. Several fixes in this session were wrong on the first attempt specifically because they were reasoned about instead of measured (the table height explosion is the clearest example) — live verification before AND after shipping is what caught it.

Also always confirm which exact CSS/JS the *live* page is actually serving (`document.querySelectorAll('style')` content, grep for the specific selector/declaration you expect) before concluding a fix did or didn't work — plugin update lag and page caching both produce "it's still broken" reports that are actually just stale deploys, not code bugs. Conversely, don't assume caching when something really is broken — reproduce it live every time.

Each `device_bash` call is a fresh shell — don't assume state (cwd, variables) persists between calls.

## Version History (recent — see `git log` for full history back to 2.9.x)

| Version | What changed |
|---|---|
| 2.16.0 | Per-role widget visibility (global `customgpt_widget_current_user_can_see_widget()` function) |
| 2.18.1–2.18.4 | Terms of Service URL sourced from the CustomGPT dashboard; settings cache fix |
| df5bb2d | SSR placeholder styling matched to the real widget (desktop + mobile) to reduce layout shift |
| 2.19.0–2.19.3 | Message feedback (thumbs up/down) fixed end to end — see "Message feedback" above for the 3 real bugs found along the way |
| 2.19.4–2.19.6 | Feedback row made permanently visible, then high-contrast, then kept the 3 buttons together as a group on mobile |
| 2.19.7–2.19.8 | Markdown table responsiveness — fixed-layout attempt shipped, reported broken (height exploded), replaced with horizontal-scroll approach |
| 2.19.9 | Citation source matching now also tries `sanitize_file_name()`'d / lowercased filename variants — **not yet re-verified live, see above** |

## Open Items / Next Steps

1. **Re-verify 2.19.9's citation matching fix live** against the specific case that prompted it (see above) — not yet done.
2. **Production is generally untested against this session's fixes** — everything has been verified on staging (`researchstaging1.adapt.com.au`) only. No indication production has even been updated to a recent version; check before assuming parity.
3. **Wide/long markdown tables are a known, accepted trade-off** (see "Markdown table responsiveness" above) — not a bug to re-fix blindly if it comes up again; understand the trade-off first.
4. **The widget's own chat-bot avatar image is fuzzy at small size** (reported with a screenshot) — root-caused to a large raster PNG (`https://assets.customgpt.ai/storage/chat_bot_avatar/...png`, 1203×990 native) being downsampled ~31x to the 38×38px avatar slot, blurring the thin strokes of the "A" mark and its red underline bar. **This is not fixable from this plugin/repo at all** — the image is hosted and set in CustomGPT's own dashboard (project's Chat Bot Avatar setting), not in WordPress. Real fix is uploading either an SVG (if CustomGPT's upload field accepts it) or a separately-exported, sharpened small raster version there. Offered to prepare that file; not yet followed up on.

## Known Gotchas

- **`.git/index.lock` / `.git/HEAD.lock`:** Appear constantly on this repo (mounted from a Windows drive into a Linux sandbox) after almost every commit. A `device_request_delete_permission` grant for the repo folder (ask the user once per session) lets the sandbox `rm -f` these itself afterward instead of asking the user to clear them via PowerShell each time — do this early in a session rather than repeatedly hitting the same friction.
- **Git commits still often succeed despite scary `warning: unable to unlink '.git/objects/.../tmp_obj_...'` noise** right after the commit — always check `git log --oneline -1` to confirm rather than assuming the warning means failure.
- **This sandbox has no GitHub push credentials** — every fix in this session was committed locally and handed to the user as `git push origin main && git tag vX.Y.Z && git push origin vX.Y.Z` for them to run.
- **No `php` binary in the sandbox** — see Diagnostic methodology above.
- **`dist/widget/*` and `plugin-update-checker/*` are third-party/compiled** — never edited directly; `git status` sometimes shows them (plus a handful of unrelated files) as modified anyway due to file-mode (644→755) changes from the Windows filesystem, not real content changes — confirmed via `git diff` showing only `old mode`/`new mode` lines. Safe to leave unstaged.
- **The browser session's login to staging expires between conversation turns/reopens** — entering credentials is a prohibited action; the user has to log back in themselves when this happens.
- **A `git push` does not update the live site** — WordPress's Plugin Update Checker has to separately detect and apply it (or the user updates manually), and caching can add further lag. Don't conclude a shipped fix is broken from a report that arrives before confirming the live version actually changed.
