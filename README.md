# CustomGPT Chat Widget

Self-hosted WordPress plugin that renders the CustomGPT.ai starter-kit chat widget via a `[customgpt_chat]` shortcode. The widget assets are pre-built and committed directly under `dist/widget/` (no build step needed on install) and are served from this plugin's own folder rather than a third-party CDN. API requests are routed through a server-side proxy so the API key never reaches the browser.

## Installing on a new site

1. Download the latest release/tag as a zip (or `git clone` this repo).
2. Upload it as a plugin the normal way: **Plugins -> Add New -> Upload Plugin**, or drop the folder into `wp-content/plugins/`.
3. Activate it.
4. Go to **Settings -> CustomGPT Chat Widget** and enter your own Agent ID and API key (found in your own CustomGPT dashboard - this repo doesn't include or require anyone else's).
5. Add `[customgpt_chat]` to any page or post.

That's it - this repo is public, so there's no token or approval needed from anyone to install it or to keep receiving updates.

Advanced/optional: both values can instead be pinned in `wp-config.php`, which takes precedence over the settings page:

```php
define( 'CUSTOMGPT_WIDGET_AGENT_ID', 'your-agent-id' );
define( 'CUSTOMGPT_WIDGET_API_KEY', 'your-api-key' );
```

There's also an optional GitHub Token field on the same settings page (and a matching `CUSTOMGPT_WIDGET_GITHUB_TOKEN` wp-config.php constant). It's not needed while this repo is public - it only matters if this repo is ever made private again, in which case the update checker needs a token to keep reading it. Safe to leave blank otherwise.

## Getting automatic updates on an existing site

Every site with this plugin already installed checks this repo periodically (roughly every 12 hours, or on-demand via the "Check for updates" link on the Plugins screen) and will show a normal WordPress "Update available" notice when a newer version is tagged here - no manual re-upload, and no need to contact the plugin's maintainer.

## Releasing a new version

1. Make your changes.
2. Bump the `Version:` header at the top of `customgpt-chat-widget.php`.
3. Commit and push to `main`.
4. Tag the release and push the tag:
   ```bash
   git tag v2.3.0
   git push --tags
   ```

Sites will pick up the new version on their next automatic check (or immediately if an admin clicks "Check for updates" on the Plugins screen).

## Fast Proxy accelerator (experimental)

`includes/customgpt-fast-proxy.php` is copied automatically into `wp-content/mu-plugins/customgpt-fast-proxy.php` on activation (and re-synced automatically after every update). It intercepts just the "create conversation" and "send message" proxy requests at WordPress's earliest possible bootstrap stage, before every other active plugin and the theme load - skipping roughly a second of overhead per message, measured live. Every other request (settings, citations, anything unrecognised) is completely untouched and falls straight through to the normal `admin-ajax.php` path, which remains the single source of truth for correctness.

Toggle it off any time under **Settings -> CustomGPT Chat Widget -> Fast Proxy** with zero other side effects - it's purely an optional accelerator layered on top of the normal proxy, never a replacement for it. Responses that went through it carry an `X-CGPT-Fast-Proxy: 1` header, visible in the browser's Network tab, for confirming it's actually engaging.

If this file is ever deleted from `wp-content/mu-plugins/` (e.g. manually, or by a migration tool that doesn't preserve mu-plugins), it reinstalls itself automatically the next time any `wp-admin` page loads - no manual step needed. Deactivating the plugin removes it.

## What's NOT in this repo

The API key and Agent ID are intentionally never committed here - this repo is public, so anything here is visible to anyone. They live only in each site's own database (via the settings page) or its own `wp-config.php`.
