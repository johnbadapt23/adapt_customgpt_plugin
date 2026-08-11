# CustomGPT Chat Widget

Self-hosted WordPress plugin that renders the CustomGPT.ai starter-kit chat widget via a `[customgpt_chat]` shortcode. The widget assets are pre-built and committed directly under `dist/widget/` (no build step needed on install) and are served from this plugin's own folder rather than a third-party CDN. API requests are routed through a server-side proxy so the API key never reaches the browser.

## Installing on a new site

1. Download the latest release/tag as a zip (or `git clone` this repo).
2. Upload it as a plugin the normal way: **Plugins -> Add New -> Upload Plugin**, or drop the folder into `wp-content/plugins/`.
3. Activate it.
4. Go to **Settings -> CustomGPT Chat Widget** and enter your Agent ID and API key (found in your CustomGPT dashboard).
5. This repo is **private** (see below), so also enter a GitHub token in the same settings page - without one, the plugin still works fine, it just won't be able to check for updates.
6. Add `[customgpt_chat]` to any page or post.

Advanced/optional: all three values can instead be pinned in `wp-config.php`, which takes precedence over the settings page:

```php
define( 'CUSTOMGPT_WIDGET_AGENT_ID', 'your-agent-id' );
define( 'CUSTOMGPT_WIDGET_API_KEY', 'your-api-key' );
define( 'CUSTOMGPT_WIDGET_GITHUB_TOKEN', 'ghp_...' );
```

## This repo is private - GitHub token required for updates

This repo is private, so the update checker needs a GitHub token to be able to read it. Without one, "Check for updates" will error out instead of finding new versions.

1. On GitHub: **Settings -> Developer settings -> Personal access tokens -> Fine-grained tokens -> Generate new token**.
2. Repository access: **Only select repositories** -> this repo.
3. Permissions: **Contents -> Read-only**. Nothing else is needed.
4. Copy the generated token (GitHub only shows it once).
5. On each WordPress site: **Settings -> CustomGPT Chat Widget -> GitHub Token**, paste it in, save. Or pin it in `wp-config.php` as shown above.

Treat this token like a password - it's committed nowhere, only ever stored in each site's own database or its own `wp-config.php`. If a token is ever pasted somewhere it shouldn't be (chat, a screenshot, a public issue, etc.), revoke it on GitHub and generate a new one.

## Getting automatic updates on an existing site

Every site with this plugin already installed (and a valid GitHub token configured, since the repo is private) checks this repo periodically (roughly every 12 hours, or on-demand via the "Check for updates" link on the Plugins screen) and will show a normal WordPress "Update available" notice when a newer version is tagged here - no manual re-upload needed after the first install.

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

The API key, Agent ID, and GitHub token are intentionally never committed here, even though the repo is private now - private repos can still be made public later, added collaborators, or forked, so none of these are ever hardcoded. They live only in each site's own database (via the settings page) or its own `wp-config.php`.
