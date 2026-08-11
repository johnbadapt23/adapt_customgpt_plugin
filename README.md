# CustomGPT Chat Widget

Self-hosted WordPress plugin that renders the CustomGPT.ai starter-kit chat widget via a `[customgpt_chat]` shortcode. The widget assets are pre-built and committed directly under `dist/widget/` (no build step needed on install) and are served from this plugin's own folder rather than a third-party CDN. API requests are routed through a server-side proxy so the API key never reaches the browser.

## Installing on a new site

1. Download the latest release/tag as a zip (or `git clone` this repo).
2. Upload it as a plugin the normal way: **Plugins -> Add New -> Upload Plugin**, or drop the folder into `wp-content/plugins/`.
3. Activate it.
4. Go to **Settings -> CustomGPT Chat Widget** and enter your Agent ID and API key (found in your CustomGPT dashboard).
5. Add `[customgpt_chat]` to any page or post.

Advanced/optional: both values can instead be pinned in `wp-config.php`, which takes precedence over the settings page:

```php
define( 'CUSTOMGPT_WIDGET_AGENT_ID', '98865' );
define( 'CUSTOMGPT_WIDGET_API_KEY', '10769|...' );
```

## Getting automatic updates on an existing site

Every site with this plugin already installed checks this repo periodically (roughly every 12 hours, or on-demand via the "Check for updates" link on the Plugins screen) and will show a normal WordPress "Update available" notice when a newer version is tagged here - no manual re-upload needed after the first install.

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

## What's NOT in this repo

The API key and Agent ID are intentionally never committed here (this repo is public). They live only in each site's own database (via the settings page) or its own `wp-config.php`.
