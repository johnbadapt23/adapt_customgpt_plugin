# CustomGPT Chat Widget — Project Handoff

Notes for picking this project back up in a fresh session (different account, no memory of prior conversations). Written from the working state as of the most recent session.

## What this is

A self-hosted WordPress plugin (`customgpt-chat-widget`) that renders the CustomGPT.ai starter-kit chat widget via a `[customgpt_chat]` shortcode. It embeds directly into the page DOM (no iframe), proxies API calls server-side so the API key never reaches the browser, and ships its own compiled widget bundle under `dist/widget/` rather than pulling from a CDN.

- **Repo:** `https://github.com/johnbadapt23/adapt_customgpt_plugin` (public, `main` branch)
- **Local working copy:** `F:\WORK\git_repo`
- **Live site (staging):** `https://researchstaging1.adapt.com.au/` — this is where all testing in this session happened
- **Production:** exists on the same server, separate WP instance, not yet verified against the latest fixes below
- **Single canonical plugin file:** `customgpt-chat-widget.php` (everything except the fast-proxy accelerator and the compiled bundle lives in this one file)
- **Current version as of last edit:** `2.11.3` (see Version History below — **verify this actually got pushed and tagged**, see Open Items)

Updates ship via a GitHub-based Plugin Update Checker (PUC) baked into the plugin — sites check the repo automatically (~every 12h, or on-demand via "Check for updates" on the Plugins screen) and see a normal WP update notice when a new version is tagged.

## Architecture

### Two-stage render: SSR placeholder → real widget

`render_hero_placeholder_html()` prints static HTML (heading, tagline, input shell, 3 question chips) directly into the page's initial HTML for LCP — this paints before the ~2MB JS bundle downloads/executes. The real widget bundle (`dist/widget/customgpt-widget.b16.min.js` + `vendors.js` + chunk files + CSS) replaces this element's contents outright once it mounts; there's no hydration.

### The proxy

All CustomGPT API calls go through `admin-ajax.php?action=customgpt_proxy&path=...` (`handle_proxy()` in the main plugin file), which holds the real API key server-side and streams SSE responses through.

### Fast-path proxy accelerator (`includes/customgpt-fast-proxy.php`)

A normal `admin-ajax.php` request pays ~1.4s of WordPress bootstrap overhead (loading all 56 active plugins + theme) before this plugin's own code even runs — measured live via a diagnostic `X-CGPT-Bootstrap-Ms` header. This file is copied into `wp-content/mu-plugins/` on plugin activation (must-use plugins load *before* regular plugins/theme), and intercepts just two endpoints — `POST /conversations` and `POST /messages` — before that overhead is paid. Every other request, and either of these two if anything looks even slightly off (disabled in settings, missing key, wrong method), falls straight through (`return`, never `exit`) to the normal, fully-featured `handle_proxy()` path. Toggle: Settings → CustomGPT Chat Widget → Fast Proxy. Responses that went through it carry `X-CGPT-Fast-Proxy: 1`.

The self-install/sync mechanism: `register_activation_hook`/`register_deactivation_hook` install/remove it; since activation hooks don't fire on version updates (only real (de)activation), `admin_init` also checks `filemtime()` and re-syncs if the source is newer than the installed copy — keeps it current after every plugin update automatically.

### Stable DOM hook classes in the compiled bundle (confirmed via direct grep of `dist/widget/*.js`)

Hand-authored, stable — safe to select on: `cgpt-hero-wrap`, `cgpt-hero-card`, `cgpt-hero-title`, `cgpt-hero-tagline`, `cgpt-input-row`, `cgpt-input-wrap`, `cgpt-msg-row`, `cgpt-beta-badge`, `cgpt-active`.

**No dedicated class exists** for: the example-question chip buttons, the send button, or the textarea — all plain Tailwind-utility-styled elements. Verified selectors instead:
- Chip button: `.cgpt-hero-card button` that is **not** inside `.cgpt-input-row`/`.cgpt-input-wrap`
- Send button: `.cgpt-hero-card button[type="submit"]` (the only other `type="submit"` button in the whole bundle is in an unrelated settings form, never inside `.cgpt-hero-card`); also has `title="Send message"` (idle) / `title="Sending message..."` (in flight)
- Hero textarea: `.cgpt-hero-card textarea` (vs. the in-conversation one at `.cgpt-input-wrap textarea`)
- Enter-to-submit: the textarea's own `onKeyDown` fires the identical submit handler (`D`) that the form's `onSubmit` and the send button both use — confirmed in the minified source. So Enter and the send button are functionally identical submissions; only chip clicks are a separate code path.

### Transition overlay (masks the wait between submit and chat mounting)

`showHeroTransitionOverlay()` in `enqueue_active_class_behavior()`'s `wp_footer` script. Creates `#cgpt-transition-overlay` (appended to `document.body`, outside React's tree so a re-render can't wipe it) plus toggles a `body.cgpt-content-hidden` class that `visibility: hidden`s the real widget's content underneath (not just covers it — no bleed-through).

**Removal is keyed on `.customgpt-chat-embed .cgpt-msg-row` appearing** — not on the hero disappearing. This was hard-won: the compiled bundle briefly unmounts *and remounts* the hero screen mid-transition (confirmed via a live instrumented timing trace the user captured), so "hero is gone" fires too early and the hero flickers back into view. `.cgpt-msg-row` appearing was the only signal that held stable across the observed trace. 20-second safety-timeout fallback in case the expected DOM signal never arrives.

**What triggers it (as of 2.11.3):** a chip click, a send-button click, or Enter-to-submit with non-empty text — all scoped to elements inside `.cgpt-hero-card`. Explicitly does **not** trigger on: clicking the textarea to focus/type, clicking blank space/padding/the card background, or any non-submit button. Getting this scoping right took three iterations (see Version History) — the failure mode each time was either "loader never shows" (frozen-look bug returns) or "loader shows for non-submissions" (blocks typing).

### `responseInFlight` guard (blocks closing mid-response)

Combines two signals: the real widget's textarea `disabled` attribute (true for the entire thinking+streaming lifecycle once a conversation exists) OR `#cgpt-transition-overlay` being present (covers the earlier window, before the textarea state means anything). Used to block the X/close button, outside-click-to-close, and Escape while true.

### Diagnostic methodology used throughout

No `php` binary in the sandbox (`apt-get install php-cli` fails with permission errors even under sudo) — PHP edits are verified with a Python brace/paren-balance count via bash, **not** a real syntax check. Eyeball logic carefully; this only catches gross mismatches.

For runtime/timing bugs, the most reliable method was: patch `window.fetch` and add capture-phase click/keydown listeners via `javascript_tool` in the browser, reproduce the interaction, then read back `window.__cgptTrace` (strip query strings from any logged URLs — the browser tool blocks output containing raw query-string data). `read_network_requests` is also useful but its tracking resets on navigation and needs to be primed with one call before the page action that triggers the requests.

## Version History (this session)

| Version | What changed |
|---|---|
| 2.9.1–2.10.2 | Built and iteratively fixed the transition overlay (see above) — 2.10.1 was a regression, 2.10.2 is the fix that held |
| 2.11.0 | Shipped the fast-path MU-plugin proxy accelerator |
| 2.11.1 | First attempt at excluding the textarea from the overlay trigger — incomplete, still fired on any click elsewhere in the hero box |
| 2.11.2 | **Version-only bump.** 2.11.1 was pushed twice under two different commits without the header changing between them — the PUC compares version *strings*, not tag SHAs, so sites already on 2.11.1 would never have seen the second fix as an update. Lesson: always bump the version header for every fix that ships, even a same-day follow-up. |
| 2.11.3 | Final overlay fix: scoped strictly to chip clicks / send-button clicks (`type="submit"`) / Enter-to-submit — everything else in the hero box is now inert to it. **Verify this was actually committed, pushed, and tagged — see Open Items.** |

## Open Items / Next Steps

1. **Verify 2.11.3 actually shipped.** The last fix in this session was written to disk and balance-checked, but committing/pushing hit the same recurring git-lock issue described below and had to be handed to the user to run manually. Check `git log` on `main` and `git tag -l` on GitHub to confirm `v2.11.3` exists and points at the right commit before assuming it's live.
2. **Production is untested against any of this session's fixes.** Everything above was verified on staging (`researchstaging1.adapt.com.au`) only.
3. **The "frozen popup" latency itself (18–30s to first content on some real page-load attempts) is not fully solved.** Investigated whether Redis (the site's Redis Object Cache plugin, using the slow pure-PHP Predis client rather than the compiled phpredis extension) was the cause — it was a red herring. Disabling Redis on staging made isolated single-request timing fast and consistent (~1.4s conversation create, ~3.7s message send), but the *real* first-click flow, traced live, still took 18–30s end to end. Root cause is the widget's own **sequential (not parallelized)** request chain — settings prefetch → create conversation → settings again → an unidentified admin-ajax POST → send message, each waited on in turn — combined with apparent genuine CustomGPT upstream latency variance. Neither is fixable from the WordPress plugin side without either patching the compiled bundle (currently treated as read-only/third-party) or getting CustomGPT support involved. **Recommendation given to the user: re-enable Redis on staging** — it wasn't the cause, so leaving it off just adds unnecessary DB load for no benefit.
4. **Thinking-dots indicator** — earlier in the broader project (task #24, already marked complete) a typing/thinking-dots indicator was built, but the user once observed it not visibly rendering during a real generation wait. Offered to investigate, never explicitly followed up on — worth a quick check if it comes up again.

## Known Gotchas

- **`.git/index.lock` / `.git/HEAD.lock` / `.git/packed-refs.lock`:** These appear constantly on this repo (mounted from a Windows drive into a Linux sandbox). Usually the underlying git operation still succeeds despite the scary "fatal" text, but not always — when it's a genuine block, the sandbox frequently can't `rm` the lock file itself (`Operation not permitted`, even as the owning user), so it has to be removed from the user's own PowerShell: `Remove-Item .git\index.lock -ErrorAction SilentlyContinue` (and `.git\HEAD.lock`, `.git\packed-refs.lock` as needed).
- **Stale tags:** creating a tag before its commit is actually pushed, or re-running `git tag vX.Y.Z` for a version that already has a tag pointing elsewhere, leaves the tag on an old commit. `git push origin vX.Y.Z` will silently say "Everything up-to-date" if the local and remote tag refs already match — even if that ref is wrong. Verify with `git rev-parse vX.Y.Z` vs `git log --oneline -1 origin/main`; fix with `git tag -d vX.Y.Z && git tag vX.Y.Z && git push --force origin vX.Y.Z`.
- **No `php` binary in the sandbox** — see Diagnostic methodology above.
- **`dist/widget/*` and `plugin-update-checker/*` are third-party/compiled** — never edited directly in this session; `git status` sometimes shows them as modified anyway (line-ending/filesystem artifacts from the Windows mount, not real changes).
