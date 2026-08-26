<?php
/**
 * Plugin Name: CustomGPT Chat Widget
 * Description: Renders the CustomGPT.ai starter-kit chat widget via a [customgpt_chat] shortcode, self-hosted from this plugin's dist/widget/ folder (not jsDelivr). The widget renders directly into the page DOM (no iframe), so it's styleable with plain CSS. API requests are routed through a server-side proxy so the API key never reaches the browser.
 * Version: 2.9.0
 * Author: ADAPT
 * Update URI: https://github.com/johnbadapt23/adapt_customgpt_plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

/*
 * Credentials.
 *
 * The API key is only ever used server-side, inside
 * CustomGPT_Chat_Widget_Plugin::handle_proxy(). It is never printed
 * into a <script> tag or sent to the browser.
 *
 * This plugin's source lives in a GitHub repo, so there is no
 * hardcoded key/agent-id here (anything in this file could end up
 * publicly readable). Configure both under Settings -> CustomGPT Chat
 * Widget in wp-admin (stored in this site's options table, never
 * committed to the repo). Power users can still override either one
 * with a wp-config.php constant (takes precedence over the settings
 * page):
 *   define( 'CUSTOMGPT_WIDGET_AGENT_ID', 'your-agent-id' );
 *   define( 'CUSTOMGPT_WIDGET_API_KEY', 'your-api-key' );
 *
 * If neither source has a value, the shortcode shows an admin-only
 * notice instead of silently failing or falling back to a shared key.
 */
if ( ! defined( 'CUSTOMGPT_API_BASE' ) ) {
	define( 'CUSTOMGPT_API_BASE', 'https://app.customgpt.ai/api/v1' );
}

/*
 * Update checker.
 *
 * Hooks this plugin into WordPress's normal "Update available" UI on
 * the Plugins screen, sourced from the GitHub repo below instead of
 * wordpress.org. To ship a new version to every site that has this
 * installed: bump the Version header above, commit, tag it
 * (`git tag vX.Y.Z && git push origin vX.Y.Z`), and push the branch.
 * No further action needed on any individual site - each one checks
 * the repo on its own schedule (roughly every 12 hours, or instantly
 * via "Check for updates" on the Plugins screen) and shows the update
 * like any other plugin.
 *
 * Works with a private repo too: create a GitHub personal access
 * token (classic token with the "repo" scope, or a fine-grained token
 * with read-only "Contents" access to just this repo) and set it
 * either under Settings -> CustomGPT Chat Widget, or as a
 * wp-config.php constant (takes precedence over the settings page):
 *   define( 'CUSTOMGPT_WIDGET_GITHUB_TOKEN', 'ghp_...' );
 * Leave it blank for a public repo - it isn't required in that case.
 */
if ( file_exists( __DIR__ . '/plugin-update-checker/plugin-update-checker.php' ) ) {
	require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
	$customgpt_widget_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/johnbadapt23/adapt_customgpt_plugin',
		__FILE__,
		'customgpt-chat-widget'
	);
	$customgpt_widget_update_checker->setBranch( 'main' );

	$customgpt_widget_github_token = defined( 'CUSTOMGPT_WIDGET_GITHUB_TOKEN' ) && '' !== CUSTOMGPT_WIDGET_GITHUB_TOKEN
		? CUSTOMGPT_WIDGET_GITHUB_TOKEN
		: get_option( 'customgpt_widget_github_token', '' );

	if ( ! empty( $customgpt_widget_github_token ) ) {
		$customgpt_widget_update_checker->setAuthentication( $customgpt_widget_github_token );
	}
}

final class CustomGPT_Chat_Widget_Plugin {

	private static $instance_count               = 0;
	private static $script_enqueued              = false;
	private static $active_class_wired           = false;
	private static $heading_patch_wired          = false;
	private static $hero_placeholder_style_wired = false;

	public function __construct() {
		add_shortcode( 'customgpt_chat', array( $this, 'render_shortcode' ) );

		// Server-side proxy: browser never sees CUSTOMGPT_WIDGET_API_KEY.
		add_action( 'wp_ajax_customgpt_proxy', array( $this, 'handle_proxy' ) );
		add_action( 'wp_ajax_nopriv_customgpt_proxy', array( $this, 'handle_proxy' ) );

		add_action( 'admin_notices', array( $this, 'maybe_show_missing_dist_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_missing_credentials_notice' ) );

		add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// "Settings" link on the Plugins list row, next to Deactivate.
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_settings_action_link' ) );

		// LCP: start fetching the JS/CSS bundle the moment the browser
		// sees <head>, instead of waiting until it reaches wp_footer
		// (where the actual <script>/<link> tags still live - this only
		// front-loads the network request). Only relevant when the
		// widget is genuinely on this page, so it's gated on
		// has_shortcode() against the raw post content - see
		// maybe_print_resource_hints().
		add_action( 'wp_head', array( $this, 'maybe_print_resource_hints' ), 1 );
	}

	/**
	 * Effective agent ID: a wp-config.php constant (if a site owner set
	 * one) always wins over the settings-page value, so power users can
	 * still pin it outside the database. Falls back to the option saved
	 * via Settings -> CustomGPT Chat Widget, then '' if neither is set.
	 */
	private function get_agent_id() {
		if ( defined( 'CUSTOMGPT_WIDGET_AGENT_ID' ) && '' !== CUSTOMGPT_WIDGET_AGENT_ID ) {
			return CUSTOMGPT_WIDGET_AGENT_ID;
		}
		return get_option( 'customgpt_widget_agent_id', '' );
	}

	/**
	 * Effective API key - same precedence as get_agent_id() above.
	 * Only ever read server-side (handle_proxy(), fetch_agent_settings_cached()).
	 */
	private function get_api_key() {
		if ( defined( 'CUSTOMGPT_WIDGET_API_KEY' ) && '' !== CUSTOMGPT_WIDGET_API_KEY ) {
			return CUSTOMGPT_WIDGET_API_KEY;
		}
		return get_option( 'customgpt_widget_api_key', '' );
	}

	/**
	 * Whether to show the "BETA" badge next to the heading. Defaults to
	 * shown (true) so existing sites keep their current look until an
	 * admin explicitly turns it off. Affects both the SSR placeholder's
	 * own badge markup (.cgpt-ssr-badge, skipped entirely below when
	 * off) and the real widget bundle's badge (.cgpt-beta-badge, which
	 * is baked into the compiled JS - hidden via a CSS override instead
	 * of touching that bundle) so both stay in sync and there's no
	 * flash of the badge appearing/disappearing when React mounts.
	 */
	private function show_beta_badge() {
		return '0' !== get_option( 'customgpt_widget_show_beta_badge', '1' );
	}

	/**
	 * The colored brand word in the heading (defaults to "ADAPT",
	 * styled in the SSR placeholder via .cgpt-ssr-brand / in the real
	 * widget via an inline color style baked into the compiled JS).
	 */
	private function get_heading_brand() {
		$value = get_option( 'customgpt_widget_heading_brand', 'ADAPT' );
		return '' !== trim( (string) $value ) ? $value : 'ADAPT';
	}

	/**
	 * The plain-text remainder of the heading, right after the brand
	 * word (defaults to "Intelligence").
	 */
	private function get_heading_suffix() {
		$value = get_option( 'customgpt_widget_heading_suffix', 'Intelligence' );
		return '' !== trim( (string) $value ) ? $value : 'Intelligence';
	}

	/**
	 * Prepends a "Settings" link to this plugin's row on the Plugins
	 * list page, pointing at Settings -> CustomGPT Chat Widget.
	 */
	public function add_settings_action_link( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=customgpt-chat-widget' ) ) . '">Settings</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	public function register_settings_page() {
		add_options_page(
			'CustomGPT Chat Widget',
			'CustomGPT Chat Widget',
			'manage_options',
			'customgpt-chat-widget',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'customgpt_chat_widget_settings',
			'customgpt_widget_agent_id',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			'customgpt_chat_widget_settings',
			'customgpt_widget_api_key',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			'customgpt_chat_widget_settings',
			'customgpt_widget_github_token',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);
		register_setting(
			'customgpt_chat_widget_settings',
			'customgpt_widget_show_beta_badge',
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
				'default'           => '1',
			)
		);
		register_setting(
			'customgpt_chat_widget_settings',
			'customgpt_widget_heading_brand',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'ADAPT',
			)
		);
		register_setting(
			'customgpt_chat_widget_settings',
			'customgpt_widget_heading_suffix',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'Intelligence',
			)
		);

		add_settings_section( 'customgpt_chat_widget_main', '', '__return_false', 'customgpt-chat-widget' );

		add_settings_field(
			'customgpt_widget_agent_id',
			'Agent ID',
			array( $this, 'render_agent_id_field' ),
			'customgpt-chat-widget',
			'customgpt_chat_widget_main'
		);
		add_settings_field(
			'customgpt_widget_api_key',
			'API Key',
			array( $this, 'render_api_key_field' ),
			'customgpt-chat-widget',
			'customgpt_chat_widget_main'
		);
		// Hidden from the settings page while this repo is public (not
		// needed - see README). The underlying option, wp-config.php
		// constant, and update-checker logic are all still fully intact
		// above; uncomment this block to bring the field back if the
		// repo ever goes private again.
		// add_settings_field(
		// 	'customgpt_widget_github_token',
		// 	'GitHub Token',
		// 	array( $this, 'render_github_token_field' ),
		// 	'customgpt-chat-widget',
		// 	'customgpt_chat_widget_main'
		// );
		add_settings_field(
			'customgpt_widget_show_beta_badge',
			'BETA Badge',
			array( $this, 'render_show_beta_badge_field' ),
			'customgpt-chat-widget',
			'customgpt_chat_widget_main'
		);
		add_settings_field(
			'customgpt_widget_heading_brand',
			'Heading Brand Text',
			array( $this, 'render_heading_brand_field' ),
			'customgpt-chat-widget',
			'customgpt_chat_widget_main'
		);
		add_settings_field(
			'customgpt_widget_heading_suffix',
			'Heading Suffix Text',
			array( $this, 'render_heading_suffix_field' ),
			'customgpt-chat-widget',
			'customgpt_chat_widget_main'
		);
	}

	/**
	 * Checkbox values are only present in $_POST when checked, so a
	 * matching hidden "0" field (see render_show_beta_badge_field())
	 * guarantees this always receives an explicit '1' or '0' rather
	 * than sometimes being skipped entirely on save.
	 */
	public function sanitize_checkbox( $value ) {
		return '1' === (string) $value ? '1' : '0';
	}

	public function render_agent_id_field() {
		$locked = defined( 'CUSTOMGPT_WIDGET_AGENT_ID' ) && '' !== CUSTOMGPT_WIDGET_AGENT_ID;
		$value  = $locked ? CUSTOMGPT_WIDGET_AGENT_ID : get_option( 'customgpt_widget_agent_id', '' );
		printf(
			'<input type="text" name="customgpt_widget_agent_id" value="%s" class="regular-text" placeholder="e.g. 98865" %s />',
			esc_attr( $value ),
			$locked ? 'disabled' : ''
		);
		if ( $locked ) {
			echo '<p class="description">Locked by a CUSTOMGPT_WIDGET_AGENT_ID constant in wp-config.php. Remove it there to manage this from here instead.</p>';
		} else {
			echo '<p class="description">The project/agent ID from your CustomGPT dashboard URL.</p>';
		}
	}

	public function render_api_key_field() {
		$locked = defined( 'CUSTOMGPT_WIDGET_API_KEY' ) && '' !== CUSTOMGPT_WIDGET_API_KEY;
		$value  = $locked ? CUSTOMGPT_WIDGET_API_KEY : get_option( 'customgpt_widget_api_key', '' );
		printf(
			'<input type="password" name="customgpt_widget_api_key" value="%s" class="regular-text" autocomplete="new-password" %s />',
			esc_attr( $value ),
			$locked ? 'disabled' : ''
		);
		if ( $locked ) {
			echo '<p class="description">Locked by a CUSTOMGPT_WIDGET_API_KEY constant in wp-config.php. Remove it there to manage this from here instead.</p>';
		} else {
			echo '<p class="description">Find this under API Keys in your CustomGPT dashboard. Only ever used server-side - never sent to the browser.</p>';
		}
	}

	public function render_github_token_field() {
		$locked = defined( 'CUSTOMGPT_WIDGET_GITHUB_TOKEN' ) && '' !== CUSTOMGPT_WIDGET_GITHUB_TOKEN;
		$value  = $locked ? CUSTOMGPT_WIDGET_GITHUB_TOKEN : get_option( 'customgpt_widget_github_token', '' );
		printf(
			'<input type="password" name="customgpt_widget_github_token" value="%s" class="regular-text" autocomplete="new-password" %s />',
			esc_attr( $value ),
			$locked ? 'disabled' : ''
		);
		if ( $locked ) {
			echo '<p class="description">Locked by a CUSTOMGPT_WIDGET_GITHUB_TOKEN constant in wp-config.php. Remove it there to manage this from here instead.</p>';
		} else {
			echo '<p class="description">Only needed if the plugin\'s GitHub repo is private. A personal access token with read-only access to that repo\'s contents - leave blank for a public repo.</p>';
		}
	}

	public function render_show_beta_badge_field() {
		?>
		<label>
			<input type="hidden" name="customgpt_widget_show_beta_badge" value="0" />
			<input type="checkbox" name="customgpt_widget_show_beta_badge" value="1" <?php checked( $this->show_beta_badge() ); ?> />
			Show the "BETA" badge next to the widget heading
		</label>
		<p class="description">Unchecking this hides the badge both before and after the widget loads.</p>
		<?php
	}

	public function render_heading_brand_field() {
		printf(
			'<input type="text" name="customgpt_widget_heading_brand" value="%s" class="regular-text" placeholder="ADAPT" />',
			esc_attr( get_option( 'customgpt_widget_heading_brand', 'ADAPT' ) )
		);
		echo '<p class="description">The colored word at the start of the heading. Defaults to "ADAPT".</p>';
	}

	public function render_heading_suffix_field() {
		printf(
			'<input type="text" name="customgpt_widget_heading_suffix" value="%s" class="regular-text" placeholder="Intelligence" />',
			esc_attr( get_option( 'customgpt_widget_heading_suffix', 'Intelligence' ) )
		);
		echo '<p class="description">The rest of the heading, right after the brand word. Defaults to "Intelligence". Together they read, e.g., "ADAPT Intelligence".</p>';
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1>CustomGPT Chat Widget</h1>
			<p>Configure the credentials used by the <code>[customgpt_chat]</code> shortcode. These are saved to this site's database and are never included in the plugin's git repo.</p>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'customgpt_chat_widget_settings' );
				do_settings_sections( 'customgpt-chat-widget' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public function maybe_show_missing_credentials_notice() {
		if ( '' !== $this->get_agent_id() && '' !== $this->get_api_key() ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<strong>CustomGPT Chat Widget:</strong>
				Agent ID and/or API key are not configured, so <code>[customgpt_chat]</code> won't render anything on the front end.
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=customgpt-chat-widget' ) ); ?>">Set them here</a>.
			</p>
		</div>
		<?php
	}

	/**
	 * True if the current request's post content contains the shortcode.
	 * wp_head runs before the shortcode itself is ever processed (that
	 * happens later, inline with the_content()), so this is the only
	 * reliable way to know from wp_head whether preloading is worth it.
	 * Won't catch the shortcode if it's injected via a widget/template
	 * rather than post content - preloading simply won't fire in that
	 * case, same as if the plugin weren't optimized for it at all.
	 */
	private function page_has_shortcode() {
		global $post;
		return $post instanceof WP_Post && has_shortcode( $post->post_content, 'customgpt_chat' );
	}

	/**
	 * Preloads the three largest, always-required assets (vendors
	 * bundle, main widget bundle, stylesheet) so the browser starts
	 * downloading them immediately rather than discovering them only
	 * once it reaches the wp_footer <script> tags. Doesn't preload the
	 * *.chunk.js files - there can be over a dozen of them and most
	 * exist for the rarely-used voice feature, so preloading all of
	 * them would compete for bandwidth with these three higher-value
	 * requests instead of helping.
	 */
	public function maybe_print_resource_hints() {
		if ( ! $this->page_has_shortcode() ) {
			return;
		}
		if ( ! file_exists( $this->widget_js_path() ) ) {
			return;
		}

		if ( file_exists( $this->vendors_js_path() ) ) {
			printf(
				'<link rel="preload" as="script" fetchpriority="high" href="%s" />' . "\n",
				esc_url( $this->vendors_js_url() )
			);
		}
		printf(
			'<link rel="preload" as="script" fetchpriority="high" href="%s" />' . "\n",
			esc_url( $this->widget_js_url() )
		);
		if ( file_exists( $this->widget_css_path() ) ) {
			printf(
				'<link rel="preload" as="style" href="%s" />' . "\n",
				esc_url( $this->widget_css_url() )
			);
		}
	}

	/**
	 * Absolute filesystem path to the self-hosted widget bundle.
	 * You build this by running, in a clone of
	 * github.com/Poll-The-People/customgpt-starter-kit:
	 *   npm install
	 *   npm run build:widget
	 * then copying the resulting dist/widget/ folder here so it sits
	 * at customgpt-chat-widget/dist/widget/customgpt-widget.b16.min.js
	 * (customgpt-widget.js, vendors.js, and customgpt-widget.css must
	 * all stay in that same folder together — the JS loads the others
	 * relative to its own URL at runtime).
	 */
	private function widget_js_path() {
		return plugin_dir_path( __FILE__ ) . 'dist/widget/customgpt-widget.b16.min.js';
	}

	private function vendors_js_path() {
		return plugin_dir_path( __FILE__ ) . 'dist/widget/vendors.b16.min.js';
	}

	private function widget_css_path() {
		return plugin_dir_path( __FILE__ ) . 'dist/widget/customgpt-widget.b16.min.css';
	}

	/**
	 * Appends a `?ver=<file mtime>` cache-busting query string to an
	 * asset URL. The dist/widget/*.js and *.css files are served with a
	 * far-future Cache-Control (max-age=31536000, i.e. one year) by the
	 * hosting/CDN layer, and their filenames never change between
	 * builds - so without this, browsers (and any CDN edge cache in
	 * front of the site) will keep serving a stale, pre-update copy
	 * indefinitely after every `npm run build:widget` + re-upload,
	 * until someone manually hard-refreshes or purges cache. Changing
	 * the URL itself on every file change is the standard fix (this is
	 * exactly what wp_enqueue_script()'s own $ver argument normally
	 * does - we're doing it by hand here because these tags are printed
	 * directly rather than going through wp_enqueue_script()).
	 */
	private function versioned_url( $url, $path ) {
		$mtime = file_exists( $path ) ? filemtime( $path ) : time();
		return add_query_arg( 'ver', $mtime, $url );
	}

	private function widget_js_url() {
		return $this->versioned_url( plugins_url( 'dist/widget/customgpt-widget.b16.min.js', __FILE__ ), $this->widget_js_path() );
	}

	private function vendors_js_url() {
		return $this->versioned_url( plugins_url( 'dist/widget/vendors.b16.min.js', __FILE__ ), $this->vendors_js_path() );
	}

	private function widget_css_url() {
		return $this->versioned_url( plugins_url( 'dist/widget/customgpt-widget.b16.min.css', __FILE__ ), $this->widget_css_path() );
	}

	public function maybe_show_missing_dist_notice() {
		if ( file_exists( $this->widget_js_path() ) ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<strong>CustomGPT Chat Widget:</strong>
				<?php echo esc_html( 'dist/widget/customgpt-widget.b16.min.js not found. Build the widget (npm run build:widget in the customgpt-starter-kit repo) and copy dist/widget/ into this plugin\'s folder, next to this file, before the [customgpt_chat] shortcode will render anything.' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Server-side fetch of GET /projects/{id}/settings, cached in a
	 * transient for 5 minutes so this only means an extra CustomGPT API
	 * round trip once every 5 minutes site-wide (shared across all
	 * visitors), not on every single page load.
	 *
	 * Originally the SSR placeholder below just printed hardcoded
	 * fallback copy, on the theory that fetching real settings
	 * server-side would add latency and work against LCP. In practice
	 * the client-side widget was ALREADY doing this exact fetch itself
	 * after mount (confirmed via the network panel: a real GET to
	 * .../settings before the example-question pills fill in) - so that
	 * round trip was happening regardless, just late enough to be
	 * visible as empty/skeleton pills. Caching it server-side and
	 * reusing the result removes the visible wait rather than just
	 * relocating it.
	 *
	 * A failed/slow fetch is cached too (briefly), so a down or slow
	 * CustomGPT API can't add multi-second latency to every page load
	 * until it recovers - callers get null back and fall back to the
	 * hardcoded copy, same as before this existed.
	 */
	private function fetch_agent_settings_cached( $agent_id ) {
		$cache_key = 'customgpt_widget_settings_' . $agent_id;
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached ? $cached : null;
		}

		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return null;
		}

		$response = wp_remote_get(
			CUSTOMGPT_API_BASE . '/projects/' . rawurlencode( (string) $agent_id ) . '/settings',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Accept'        => 'application/json',
				),
				'timeout' => 3,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( $cache_key, array(), MINUTE_IN_SECONDS );
			return null;
		}

		$raw_body = wp_remote_retrieve_body( $response );

		// The widget bundle hits this exact same GET /projects/{id}/settings
		// endpoint a SECOND time on its own, via the generic proxy
		// (handle_proxy() -> proxy_settings_request()), for its chat-
		// initialization flow - a separate code path from the hero-display
		// prefetch this function exists for, needing the full raw response
		// rather than just the 3 fields cached below. Since this function
		// already runs on every page load (well before any visitor could
		// possibly click fast enough to beat it), priming that other
		// cache here means the widget's own settings fetch is very likely
		// already warm by the time anyone clicks - turning what would be
		// another ~1+ second round trip (on top of WordPress's own
		// admin-ajax bootstrap cost) into a near-instant cache hit,
		// without needing to touch the compiled bundle at all.
		set_transient( 'customgpt_widget_raw_settings_' . $agent_id, array( 'body' => $raw_body ), 5 * MINUTE_IN_SECONDS );

		$body = json_decode( $raw_body, true );
		$data = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : $body;

		if ( ! is_array( $data ) ) {
			set_transient( $cache_key, array(), MINUTE_IN_SECONDS );
			return null;
		}

		$settings = array(
			'example_questions'        => isset( $data['example_questions'] ) && is_array( $data['example_questions'] )
				? array_values( array_slice( $data['example_questions'], 0, 3 ) )
				: array(),
			'default_prompt'           => isset( $data['default_prompt'] ) ? (string) $data['default_prompt'] : '',
			'try_asking_questions_msg' => isset( $data['try_asking_questions_msg'] ) ? (string) $data['try_asking_questions_msg'] : '',
		);

		set_transient( $cache_key, $settings, 5 * MINUTE_IN_SECONDS );
		return $settings;
	}

	/**
	 * Static HTML (+ inline critical CSS, printed once) matching the
	 * widget's own initial hero screen - heading, tagline, input shell,
	 * three prompt pills. Printed directly inside the shortcode's
	 * container div, so it paints as part of the page's initial HTML
	 * instead of waiting on the ~2MB JS bundle to download, execute,
	 * and mount React. This IS the actual LCP fix: the preload hints in
	 * maybe_print_resource_hints() only make the bundle arrive sooner,
	 * they don't remove the dependency on it.
	 *
	 * $settings is whatever fetch_agent_settings_cached() returned (or
	 * null on a cache miss/failed fetch) - when real example questions
	 * are available they're printed as real text instead of the empty
	 * pulsing skeleton pills, and the tagline/placeholder use the
	 * agent's actual current dashboard copy instead of hardcoded
	 * fallback strings.
	 *
	 * React replaces this element's contents outright once it mounts
	 * (no hydration/reconciliation against it). Also prints a small
	 * inline script setting window.__cgpt_prefetched_settings, which a
	 * matching patch in the widget bundle checks before making its own
	 * settings fetch - when this data is already here, it skips that
	 * fetch (and the skeleton-pill state) entirely instead of the
	 * placeholder's real content being replaced by a skeleton flash
	 * while React re-fetches the same thing.
	 */
	private function render_hero_placeholder_html( $atts, $settings ) {
		ob_start();

		if ( ! self::$hero_placeholder_style_wired ) {
			self::$hero_placeholder_style_wired = true;
			?>
			<style>
				.cgpt-ssr-hero{font-family:-apple-system,BlinkMacSystemFont,"Helvetica Neue",Arial,sans-serif;padding:40px;box-sizing:border-box}
				.cgpt-ssr-hero *{box-sizing:border-box}
				.cgpt-ssr-hero-inner{text-align:center}
				.cgpt-ssr-title{font-weight:400;font-size:48px;color:#1a1a1a;margin:0 0 4px;letter-spacing:-0.01em;line-height:1.2;display:inline-block;position:relative}
				.cgpt-ssr-badge{position:absolute;top:0;right:0;transform:translate(15%,calc(-100% - 2px));background:#000;color:#fff;font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;padding:3px 8px;border-radius:4px;line-height:1;white-space:nowrap}
				.cgpt-ssr-brand{color:#E7534F}
				.cgpt-ssr-tagline{font-size:16px;color:#222;margin:0 0 24px;line-height:1.5}
				.cgpt-ssr-card{background:#fff;border:1px solid #e5e7eb;border-radius:4px;box-shadow:0 20px 25px -5px rgba(0,0,0,.1),0 8px 10px -6px rgba(0,0,0,.1);padding:24px;text-align:left}
				.cgpt-ssr-input{display:flex;align-items:center;justify-content:space-between;gap:8px;border:1px solid #e5e7eb;border-radius:4px;padding:12px;margin-bottom:24px}
				.cgpt-ssr-input-placeholder{color:#9ca3af;font-size:14px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
				.cgpt-ssr-send-btn{flex-shrink:0;width:36px;height:36px;border-radius:4px;background:#f3a6a3;color:#fff;display:flex;align-items:center;justify-content:center;font-size:16px;line-height:1}
				.cgpt-ssr-chips{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
				.cgpt-ssr-chip{min-height:50px;border-radius:4px;background:#FDF1F1}
				@media (min-width:640px){
					.cgpt-ssr-card{padding:32px}
					.cgpt-ssr-chips{gap:12px}
				}
				@media (max-width:639.98px){
					.cgpt-ssr-hero{padding:24px 0 32px}
					.cgpt-ssr-title{font-size:28px}
					.cgpt-ssr-badge{font-size:9px;padding:2px 6px}
					.cgpt-ssr-tagline{font-size:13px;margin-bottom:16px}
					.cgpt-ssr-chips{grid-template-columns:1fr}
				}
				.cgpt-ssr-chip-text{display:flex;align-items:center;justify-content:flex-start;text-align:left;padding:10px 12px;font-size:13px;color:#1a1a1a;background:#FDF1F1}
				/* First-interaction loading affordance: the placeholder's
				   chips/input have no handler of their own until the real
				   widget bundle mounts and replaces this markup outright
				   (see enqueue_active_class_behavior()'s click listener,
				   which adds this class on click). Without this, a click
				   that lands before the bundle finishes loading looks like
				   nothing happened at all for however long that takes. */
				.cgpt-ssr-card{position:relative}
				.cgpt-ssr-hero.cgpt-ssr-loading .cgpt-ssr-input,
				.cgpt-ssr-hero.cgpt-ssr-loading .cgpt-ssr-chips{opacity:.45;pointer-events:none}
				.cgpt-ssr-hero.cgpt-ssr-loading .cgpt-ssr-card::after{
					content:"";
					position:absolute;
					top:50%;
					left:50%;
					width:28px;
					height:28px;
					margin:-14px 0 0 -14px;
					border:3px solid #e5e7eb;
					border-top-color:#E7534F;
					border-radius:50%;
					animation:cgpt-ssr-spin .7s linear infinite;
				}
				@keyframes cgpt-ssr-spin{to{transform:rotate(360deg)}}
				@media (prefers-reduced-motion: reduce){
					.cgpt-ssr-hero.cgpt-ssr-loading .cgpt-ssr-card::after{animation:none}
				}
			</style>
			<?php
			if ( ! $this->show_beta_badge() ) {
				// Hides the real, post-mount widget's own badge too -
				// that markup is baked into the compiled JS bundle
				// (.cgpt-beta-badge), so this CSS override is the only
				// way to turn it off without patching that bundle.
				echo '<style>.cgpt-beta-badge{display:none!important}</style>';
			}
		}

		$example_questions = ! empty( $settings['example_questions'] ) ? $settings['example_questions'] : array();
		$tagline           = ! empty( $settings['try_asking_questions_msg'] ) ? $settings['try_asking_questions_msg'] : 'Find the insight. Frame your message. All in one place.';
		$input_placeholder = ! empty( $settings['default_prompt'] ) ? $settings['default_prompt'] : 'Ask me about a topic, persona, or sector.';

		// Consumed by the widget bundle's own WelcomeMessage fetch effect
		// (patched to check this before hitting the network) - keyed by
		// agent id so multiple [customgpt_chat] instances on one page
		// with different agent_id overrides don't collide.
		?>
		<script nowprocket data-no-minify="1">
		window.__cgpt_prefetched_settings = window.__cgpt_prefetched_settings || {};
		window.__cgpt_prefetched_settings[<?php echo wp_json_encode( (string) $atts['agent_id'] ); ?>] = <?php echo wp_json_encode( $settings ? $settings : array(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP ); ?>;
		</script>
		<div class="cgpt-ssr-hero">
			<div class="cgpt-ssr-hero-inner">
				<h1 class="cgpt-ssr-title"><span class="cgpt-ssr-brand"><?php echo esc_html( $this->get_heading_brand() ); ?></span> <?php echo esc_html( $this->get_heading_suffix() ); ?><?php echo $this->show_beta_badge() ? '<span class="cgpt-ssr-badge">BETA</span>' : ''; ?></h1>
				<p class="cgpt-ssr-tagline"><?php echo esc_html( $tagline ); ?></p>
				<div class="cgpt-ssr-card">
					<div class="cgpt-ssr-input">
						<span class="cgpt-ssr-input-placeholder"><?php echo esc_html( $input_placeholder ); ?></span>
						<span class="cgpt-ssr-send-btn" aria-hidden="true">&#8594;</span>
					</div>
					<div class="cgpt-ssr-chips">
						<?php if ( ! empty( $example_questions ) ) : ?>
							<?php foreach ( $example_questions as $question ) : ?>
								<div class="cgpt-ssr-chip cgpt-ssr-chip-text"><?php echo esc_html( (string) $question ); ?></div>
							<?php endforeach; ?>
						<?php else : ?>
							<div class="cgpt-ssr-chip"></div>
							<div class="cgpt-ssr-chip"></div>
							<div class="cgpt-ssr-chip"></div>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Usage:
	 *   [customgpt_chat]
	 *   [customgpt_chat mode="floating" position="bottom-right" theme="dark"]
	 *   [customgpt_chat mode="embedded" width="100%" height="600px"]
	 *   [customgpt_chat agent_id="123"]   (override agent per-instance; the API key always stays server-side)
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'agent_id'   => $this->get_agent_id(),
				'agent_name' => '',
				'mode'       => 'embedded', // embedded | floating
				'position'   => 'bottom-right',
				'theme'      => 'light',
				// Wider/shorter than a typical chat-bubble box: the hero
				// layout (title + tagline + input + 3-column question
				// pills) needs room to breathe horizontally, and doesn't
				// need much height until a real conversation starts.
				// Height defaults to "auto" so the box only takes up as
				// much vertical space as its content needs at rest; it
				// expands to a fixed size on its own once activated (see
				// enqueue_active_class_behavior()).
				//
				// A flat "900px" here was wider than most phone screens,
				// so on mobile the browser had to shrink the whole widget
				// (and everything in it - text included) just to make it
				// fit, instead of the hero content reflowing to the
				// screen the way the rest of the page does. min() caps it
				// at 900px on desktop (unchanged from before) but falls
				// back to the actual viewport width minus 40px - i.e. a
				// flush 20px on each side - on any screen narrower than
				// that, matching the ~20px side margin the rest of the
				// page already uses below this widget.
				'width'      => 'min(900px, calc(100vw - 40px))',
				'height'     => 'auto',
			),
			$atts,
			'customgpt_chat'
		);

		if ( ! file_exists( $this->widget_js_path() ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				return '<p style="border:1px solid #d63638;padding:10px;color:#d63638;">CustomGPT widget: dist/widget/customgpt-widget.b16.min.js is missing from the plugin folder. (Only visible to admins.)</p>';
			}
			return '';
		}

		self::$instance_count++;
		$container_id = 'customgpt-chat-' . self::$instance_count;

		$this->enqueue_widget_script();

		// The widget builds request URLs as apiBaseUrl + '/projects/...'.
		// Ending the base in "&path=" means that concatenation lands the
		// widget's own sub-path straight into our "path" query var.
		$proxy_base = add_query_arg(
			array( 'action' => 'customgpt_proxy' ),
			admin_url( 'admin-ajax.php' )
		) . '&path=';

		$config = array(
			'agentId'    => $atts['agent_id'],
			// The chat/conversation code path reads apiBaseUrl (string-
			// concatenates it with endpoints directly). The agent-details
			// code path (name, avatar, example questions) reads a
			// DIFFERENT key, apiUrl, and silently falls back to its own
			// hardcoded "/api/proxy" (relative to this page's own origin,
			// which doesn't exist here) if apiUrl isn't set — even though
			// apiBaseUrl is set correctly. Both must point at the proxy.
			'apiBaseUrl' => $proxy_base,
			'apiUrl'     => $proxy_base,
			'mode'       => $atts['mode'],
			'theme'      => $atts['theme'],
			// The widget's own JS has internal width/height defaults
			// (400px/600px) that it applies directly to the container at
			// mount time, overriding whatever inline CSS we put on the
			// wrapper div below. These must be passed into init() itself,
			// not just set as HTML/CSS, or they're silently ignored.
			'width'      => $atts['width'],
			'height'     => $atts['height'],
		);

		if ( ! empty( $atts['agent_name'] ) ) {
			$config['agentName'] = $atts['agent_name'];
		}

		ob_start();

		if ( 'embedded' === $atts['mode'] ) {
			$config['containerId'] = $container_id;
			$prefetched_settings    = $this->fetch_agent_settings_cached( $atts['agent_id'] );
			printf(
				// margin:0 auto matches what the widget's own JS applies to
				// this same element once it mounts (see render() in
				// widget/index.tsx: Object.assign(this.container.style,
				// {..., margin:"0 auto"})). Without it here too, the SSR
				// placeholder sits flush left against its parent for
				// however long the JS bundle takes to load, then visibly
				// snaps into centered position the instant it mounts -
				// exactly the jump reported.
				'<div id="%1$s" class="customgpt-chat-embed" style="width:%2$s;height:%3$s;margin:0 auto;display:block;">%4$s</div>' . "\n",
				esc_attr( $container_id ),
				esc_attr( $atts['width'] ),
				esc_attr( $atts['height'] ),
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_hero_placeholder_html() escapes every dynamic value it prints (esc_html for text, wp_json_encode for the script payload); nothing here is printed raw.
				$this->render_hero_placeholder_html( $atts, $prefetched_settings )
			);
			$this->enqueue_active_class_behavior();
			$this->enqueue_heading_patch_behavior();
		} else {
			$config['position'] = $atts['position'];
		}
		?>
		<script nowprocket data-no-minify="1">
		( function () {
			function initCustomGPT() {
				if ( typeof CustomGPTWidget === 'undefined' ) {
					setTimeout( initCustomGPT, 50 );
					return;
				}
				CustomGPTWidget.init( <?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP ); ?> );
			}
			initCustomGPT();
		} )();
		</script>
		<?php

		return ob_get_clean();
	}

	/**
	 * Finds every *.chunk.js file that belongs to the CURRENT build,
	 * next to customgpt-widget.js. Webpack gives these content-hashed
	 * names that change on every build (e.g.
	 * 19.83a53b190e9820ed998d.chunk.js), so we can't hardcode filenames.
	 *
	 * Reads dist/widget/chunks-manifest.json (written by
	 * scripts/copy-widget-assets.js during `npm run build:widget`) for
	 * the authoritative list, falling back to a bare glob only if that
	 * manifest is missing (e.g. a zip built before this fix existed).
	 *
	 * The manifest matters because if a new zip is ever extracted on top
	 * of an older deployment without first emptying dist/widget/ (easy
	 * to do by accident - the folder still "exists" either way), a bare
	 * glob picks up BOTH the old build's leftover chunk files and the
	 * new ones. Those get pushed into the same global webpack chunk
	 * registry; since chunk IDs are small sequential integers assigned
	 * per build, an old chunk and a new chunk can collide on the same ID
	 * while containing completely different modules - producing the
	 * exact "Cannot read properties of undefined (reading 'default')"
	 * class of failure (or a silently blank widget) this plugin has hit
	 * before, just triggered by stale leftovers instead of missing
	 * files. The manifest sidesteps this entirely: only the files this
	 * specific build actually produced get enqueued, regardless of what
	 * else is sitting in the folder.
	 */
	private function chunk_js_paths() {
		$dir = plugin_dir_path( __FILE__ ) . 'dist/widget/';
		$manifest_path = $dir . 'chunks-manifest.json';

		if ( file_exists( $manifest_path ) ) {
			$manifest_json = file_get_contents( $manifest_path );
			$manifest      = json_decode( (string) $manifest_json, true );

			if ( is_array( $manifest ) && ! empty( $manifest['chunks'] ) && is_array( $manifest['chunks'] ) ) {
				$paths = array();
				foreach ( $manifest['chunks'] as $chunk_filename ) {
					// Guard against path traversal / anything unexpected in
					// a manifest that's still just a JSON file on disk.
					$chunk_filename = basename( (string) $chunk_filename );
					$chunk_path     = $dir . $chunk_filename;
					if ( file_exists( $chunk_path ) ) {
						$paths[] = $chunk_path;
					}
				}
				return $paths;
			}
		}

		// Fallback for zips built before the manifest existed.
		$found = glob( $dir . '*.chunk.js' );
		return $found ? $found : array();
	}

	private function enqueue_widget_script() {
		if ( self::$script_enqueued ) {
			return;
		}
		self::$script_enqueued = true;

		$widget_src  = $this->widget_js_url();
		$vendors_src = $this->vendors_js_url();
		$css_src     = $this->widget_css_url();
		$has_vendors = file_exists( $this->vendors_js_path() );
		$has_css     = file_exists( $this->widget_css_path() );

		$chunk_srcs = array();
		foreach ( $this->chunk_js_paths() as $chunk_path ) {
			$chunk_srcs[] = plugins_url( 'dist/widget/' . basename( $chunk_path ), __FILE__ );
		}

		add_action(
			'wp_footer',
			function () use ( $widget_src, $vendors_src, $css_src, $has_vendors, $has_css, $chunk_srcs ) {
				if ( $has_css ) {
					printf(
						'<link rel="stylesheet" href="%s" />' . "\n",
						esc_url( $css_src )
					);
				}
				// vendors.js and every *.chunk.js file must load and
				// execute BEFORE customgpt-widget.js. The widget bundle
				// reads from them synchronously on startup rather than
				// fetching them lazily the way webpack normally would.
				// Skipping any of them throws "Cannot read properties of
				// undefined (reading 'default')" partway through init —
				// CustomGPTWidget may still get defined, but agent
				// settings (welcome text, example questions) silently
				// fail to load and generic fallback text is shown instead.
				if ( $has_vendors ) {
					printf(
						'<script nowprocket data-no-minify="1" src="%s"></script>' . "\n",
						esc_url( $vendors_src )
					);
				}
				foreach ( $chunk_srcs as $chunk_src ) {
					printf(
						'<script nowprocket data-no-minify="1" src="%s"></script>' . "\n",
						esc_url( $chunk_src )
					);
				}
				printf(
					'<script nowprocket data-no-minify="1" src="%s"></script>' . "\n",
					esc_url( $widget_src )
				);
			},
			// Runs AFTER enqueue_active_class_behavior()'s wp_footer hook
			// (priority 1) on purpose: the click/focus listeners that expand
			// the widget to fullscreen must be attached to the document
			// before the browser starts downloading and executing this
			// bundle (vendors.js + every *.chunk.js + customgpt-widget.js,
			// several hundred KB combined), not after. Otherwise a click
			// that lands while that bundle is still loading/parsing (very
			// plausible - a real visitor's first click on the page often
			// happens within the first second or two) has no listener to
			// catch it yet, so nothing visibly happens until the bundle
			// finishes loading and React's own onClick handler takes over -
			// which can be several seconds later on a slow connection or
			// a busy main thread. The generic document-level listener has
			// no such dependency, since it only needs the container element
			// (already present in the initial HTML) to exist, not any JS
			// bundle to have finished loading.
			20
		);
	}

	/**
	 * Wires up the "expand to fullscreen on interaction" behaviour so
	 * this self-hosted widget matches the chat box already used
	 * elsewhere on this site (researchstaging1.adapt.com.au homepage):
	 * clicking or focusing anything inside the widget adds a
	 * `cgpt-active` class to <body>; a matching CSS block below expands
	 * the widget into a centered fixed-position overlay with a dimmed
	 * backdrop and the same "rise" animation used there. Escape, or a
	 * click outside the widget, collapses it back down.
	 *
	 * Because this widget renders straight into the page DOM (no
	 * cross-origin iframe), a plain document-level click/focus listener
	 * is enough — no postMessage bridge needed, unlike the iframe-based
	 * embed on the homepage.
	 *
	 * Runs once per page regardless of how many [customgpt_chat]
	 * instances it contains.
	 */
	private function enqueue_active_class_behavior() {
		if ( self::$active_class_wired ) {
			return;
		}
		self::$active_class_wired = true;

		add_action(
			'wp_footer',
			function () {
				?>
				<style>
					body.cgpt-active { overflow: hidden; }
					body.cgpt-active::before {
						content: "";
						position: fixed;
						inset: 0;
						background: rgba( 0, 0, 0, .5 );
						z-index: 999998;
						animation: cgpt-fade .4s ease both;
					}
					.customgpt-chat-embed {
						transition: none;
					}
					body.cgpt-active .customgpt-chat-embed {
						position: fixed !important;
						inset: 0;
						margin: auto !important;
						z-index: 999999;
						width: min( 95%, 1200px ) !important;
						height: min( 800px, 90vh ) !important;
						max-height: 90vh !important;
						animation: cgpt-rise .5s cubic-bezier( .22, 1, .36, 1 ) both;
					}
					/* The React widget's own root element has its own
					   fixed 600px/400px sizing baked in; force it to fill
					   whatever size the fullscreen overlay above is, so
					   the chat itself actually expands too. */
					body.cgpt-active .customgpt-chat-embed > * {
						width: 100% !important;
						height: 100% !important;
					}
					/* The host site's own theme applies a global
					   text-transform: uppercase to form fields, which was
					   bleeding into the hero input's placeholder ("ASK ME
					   ABOUT A TOPIC, PERSONA, OR SECTOR."). Reset it back to
					   normal case here and apply the requested placeholder
					   styling, scoped to just this widget. */
					.customgpt-chat-embed textarea {
						text-transform: none !important;
					}
					.customgpt-chat-embed textarea::placeholder {
						text-transform: none !important;
						font-size: 14px !important;
						font-weight: 700 !important;
						color: #838383 !important;
					}
					/* Tailwind Typography writes its .prose hr rule with
					   :where(), which has zero specificity on purpose (so
					   consumers can override it) - meaning almost any
					   normal-specificity hr rule from the host theme beats
					   it, collapsing the divider to invisible. Restore it
					   here, scoped to just this widget. */
					.customgpt-chat-embed .prose hr {
						height: 1px !important;
						width: 100% !important;
					}
					@keyframes cgpt-rise {
						0%   { opacity: 0; transform: translateY( 28px ) scale( .96 ); }
						100% { opacity: 1; transform: none; }
					}
					@keyframes cgpt-fade {
						0%   { opacity: 0; }
						100% { opacity: 1; }
					}
					@media ( prefers-reduced-motion: reduce ) {
						body.cgpt-active .customgpt-chat-embed,
						body.cgpt-active::before {
							animation: none !important;
						}
					}
				</style>
				<script nowprocket data-no-minify="1">
				( function () {
					// Opening the popup is handled entirely by the React
					// app itself (ChatContainer's handleExamplePrompt and
					// handleSendMessage both call
					// document.body.classList.add('cgpt-active') directly),
					// scoped to exactly two triggers: clicking an example
					// question chip, or submitting the hero input with
					// typed text. This script does NOT add any other
					// "click/focus anywhere in the widget opens it"
					// listener on top of that - only closing (reset) is
					// handled here, plus a watchdog that guards against the
					// popup ever closing itself outside of an explicit
					// close action.
					function resetAndDeactivate() {
						var btn = document.querySelector( '.customgpt-chat-embed [aria-label="New conversation"]' );
						if ( btn ) {
							btn.click();
						} else {
							document.body.classList.remove( 'cgpt-active' );
						}
					}

					// The widget's own "New conversation" (pencil) and
					// "Close" (X) buttons already add/remove cgpt-active
					// (and reset the conversation) themselves.
					function isOwnChromeButton( target ) {
						return !!( target.closest && (
							target.closest( '.customgpt-chat-embed [aria-label="New conversation"]' ) ||
							target.closest( '.customgpt-chat-embed [aria-label="Close"]' )
						) );
					}

					// Any removal of cgpt-active must be preceded by one of
					// these three intentional close actions (pencil/X click,
					// click outside the widget, or Escape). The watchdog
					// below uses this flag to tell "the user meant to close
					// this" apart from "something else made it disappear" -
					// e.g. a timing edge case during the send flow that
					// otherwise made the popup look like it silently closed
					// itself right after asking a question.
					var resetRequested = false;
					function markResetRequested() {
						resetRequested = true;
						setTimeout( function () { resetRequested = false; }, 1000 );
					}

					document.addEventListener(
						'click',
						function ( e ) {
							if ( isOwnChromeButton( e.target ) ) {
								markResetRequested();
								return;
							}
							if ( ! ( e.target.closest && e.target.closest( '.customgpt-chat-embed' ) ) ) {
								if ( document.body.classList.contains( 'cgpt-active' ) ) {
									markResetRequested();
									resetAndDeactivate();
								}
							}
						},
						true
					);

					document.addEventListener( 'keydown', function ( e ) {
						if ( 'Escape' === e.key ) {
							markResetRequested();
							resetAndDeactivate();
						}
					} );

					// SSR-placeholder loading affordance (see the matching
					// CSS in render_hero_placeholder_html()). The
					// placeholder's chips/input are plain markup with no
					// handler of their own until the real widget bundle
					// finishes loading and replaces this container's
					// contents outright - if a visitor's first click on the
					// page lands on one of them before that swap happens,
					// this gives instant visual feedback (spinner, disabled
					// state) instead of leaving them looking at an
					// unresponsive placeholder for however long the bundle
					// takes to load and mount. Capture phase, and never
					// calls preventDefault/stopPropagation, so it can never
					// block whatever the widget bundle's own handlers do
					// with the same click once they're attached; once React
					// mounts it removes this markup entirely, so this
					// listener simply stops matching anything on later
					// clicks. (This whole hook must run at a lower
					// wp_footer priority than enqueue_widget_script()'s -
					// see the priority argument a few lines below - or the
					// blocking <script src="vendors.b16.min.js"> tag prints
					// before this listener is even attached, defeating the
					// entire point.)
					document.addEventListener(
						'click',
						function ( e ) {
							var target = e.target.closest && e.target.closest( '.cgpt-ssr-chip, .cgpt-ssr-input' );
							if ( ! target ) {
								return;
							}
							var hero = target.closest( '.cgpt-ssr-hero' );
							if ( hero ) {
								hero.classList.add( 'cgpt-ssr-loading' );
							}
						},
						true
					);

					// Retry-once workaround for a first-click race inside the
					// REAL (already-mounted) widget bundle itself - distinct
					// from the SSR-placeholder case above. Confirmed live:
					// on the very first click after a page loads, the
					// example-question/input buttons can already be
					// painted and focusable (they visibly take focus)
					// before their own onClick handler is actually wired
					// up inside the compiled bundle - so the click does
					// nothing else: no conversation starts, cgpt-active
					// never gets added. Every later click on the same
					// button works fine. This is inside
					// dist/widget/customgpt-widget.b16.min.js, a pre-built
					// third-party bundle we don't edit, so we can't fix the
					// handler wiring directly - instead, if a click lands
					// inside a mounted widget and cgpt-active still hasn't
					// appeared shortly after, replay the same click once.
					// By then the real handler is virtually always
					// attached, so the replay behaves exactly like any
					// other click on an already-interactive widget.
					// Excludes the SSR placeholder (handled above) since
					// replaying a click there can never help - React
					// replaces that markup outright rather than attaching
					// handlers to it.
					var retriedClickTargets = new WeakSet();
					document.addEventListener(
						'click',
						function ( e ) {
							if ( document.body.classList.contains( 'cgpt-active' ) ) {
								return;
							}
							if ( ! ( e.target.closest && e.target.closest( '.customgpt-chat-embed' ) ) ) {
								return;
							}
							if ( e.target.closest( '.cgpt-ssr-chip, .cgpt-ssr-input' ) ) {
								return;
							}
							if ( isOwnChromeButton( e.target ) ) {
								return;
							}
							var target = e.target;
							if ( retriedClickTargets.has( target ) ) {
								return;
							}
							setTimeout( function () {
								if ( document.body.classList.contains( 'cgpt-active' ) ) {
									return;
								}
								if ( ! document.body.contains( target ) ) {
									return;
								}
								retriedClickTargets.add( target );
								target.dispatchEvent( new MouseEvent( 'click', { bubbles: true, cancelable: true, view: window } ) );
							}, 400 );
						},
						true
					);

					// Watchdog: once active, cgpt-active should only ever
					// go away because of one of the intentional close
					// actions above. If it disappears for any other reason
					// - a stray re-render, a race in the conversation-setup
					// flow, anything - put it straight back so the popup
					// can never appear to close itself mid-conversation.
					var wasActive = document.body.classList.contains( 'cgpt-active' );
					var watchdog = new MutationObserver( function () {
						var isActive = document.body.classList.contains( 'cgpt-active' );
						if ( wasActive && ! isActive && ! resetRequested ) {
							document.body.classList.add( 'cgpt-active' );
							isActive = true;
						}
						wasActive = isActive;
					} );
					watchdog.observe( document.body, { attributes: true, attributeFilter: [ 'class' ] } );
				} )();
				</script>
				<?php
			},
			// Must be lower than enqueue_widget_script()'s wp_footer
			// priority (20) - both this closure's click/focus listeners
			// and the SSR-placeholder loading-affordance listener above
			// need to be attached before the blocking
			// vendors.b16.min.js/customgpt-widget.b16.min.js <script src>
			// tags print, or a click that lands while those are still
			// downloading has nothing to catch it. This used to say
			// "priority 1" in a comment without the code actually using
			// it - both hooks were really running at 20, in registration
			// order, so this one was consistently losing the race.
			1
		);
	}

	/**
	 * Patches the real (post-mount) widget's own heading text to match
	 * the Heading Brand/Suffix settings. The SSR placeholder's heading
	 * is plain PHP-rendered markup (see render_hero_placeholder_html())
	 * so it's trivial to make dynamic there, but the real widget's
	 * heading is baked into the compiled JS bundle as literal JSX
	 * (unlike the BETA badge, it has no dedicated class we could target
	 * with a CSS override) - so this patches the actual DOM text nodes
	 * once the real <h1 class="cgpt-hero-title"> exists, and keeps
	 * re-patching if the widget ever re-renders that heading (e.g. on
	 * "New conversation") and would otherwise put the stock "ADAPT
	 * Intelligence" text back.
	 *
	 * Only enqueued at all when the site has actually customized either
	 * value away from the stock defaults - no extra JS for sites that
	 * haven't touched this setting.
	 */
	private function enqueue_heading_patch_behavior() {
		if ( self::$heading_patch_wired ) {
			return;
		}
		if ( 'ADAPT' === $this->get_heading_brand() && 'Intelligence' === $this->get_heading_suffix() ) {
			return;
		}
		self::$heading_patch_wired = true;

		$brand  = $this->get_heading_brand();
		$suffix = $this->get_heading_suffix();

		add_action(
			'wp_footer',
			function () use ( $brand, $suffix ) {
				?>
				<script nowprocket data-no-minify="1">
				( function () {
					var CGPT_HEADING_BRAND = <?php echo wp_json_encode( $brand ); ?>;
					var CGPT_HEADING_SUFFIX = <?php echo wp_json_encode( $suffix ); ?>;

					function patchHeading( h1 ) {
						// Expected children, in order: [0] the colored brand
						// span, [1] a plain text node (" Intelligence"),
						// optionally [2] the .cgpt-beta-badge span - left
						// alone here, handled separately by the BETA Badge
						// setting.
						if ( ! h1 || h1.childNodes.length < 2 ) {
							return;
						}
						var brandNode = h1.childNodes[0];
						var suffixNode = h1.childNodes[1];
						if ( brandNode && brandNode.nodeType === 1 && brandNode.textContent !== CGPT_HEADING_BRAND ) {
							brandNode.textContent = CGPT_HEADING_BRAND;
						}
						var wantedSuffixText = ' ' + CGPT_HEADING_SUFFIX;
						if ( suffixNode && suffixNode.nodeType === 3 && suffixNode.textContent !== wantedSuffixText ) {
							suffixNode.textContent = wantedSuffixText;
						}
					}

					function scanAndPatch( root ) {
						if ( ! root || ! root.querySelectorAll ) {
							return;
						}
						var headings = root.querySelectorAll( 'h1.cgpt-hero-title' );
						for ( var i = 0; i < headings.length; i++ ) {
							patchHeading( headings[ i ] );
						}
					}

					scanAndPatch( document );

					var headingObserver = new MutationObserver( function ( mutations ) {
						for ( var i = 0; i < mutations.length; i++ ) {
							var m = mutations[ i ];
							if ( m.target && m.target.nodeType === 1 && m.target.matches && m.target.matches( 'h1.cgpt-hero-title' ) ) {
								patchHeading( m.target );
							}
							if ( m.addedNodes ) {
								for ( var j = 0; j < m.addedNodes.length; j++ ) {
									var node = m.addedNodes[ j ];
									if ( node.nodeType !== 1 ) {
										continue;
									}
									if ( node.matches && node.matches( 'h1.cgpt-hero-title' ) ) {
										patchHeading( node );
									}
									scanAndPatch( node );
								}
							}
						}
					} );
					headingObserver.observe( document.body, { childList: true, subtree: true, characterData: true } );
				} )();
				</script>
				<?php
			},
			20
		);
	}

	/**
	 * Best-effort lookup of a media library attachment matching the
	 * given citation title (e.g. "CIO-Persona-Profile-v.2.pdf" as shown
	 * in the citations list). Tries the exact filename first; if
	 * that's not found, retries the same base name against a few
	 * common office-document extensions before giving up - CustomGPT
	 * citations are sometimes a PDF exported specifically for upload
	 * there, while the file actually living in this site's media
	 * library is still the original source format it was converted
	 * from (e.g. the source PPTX). Every individual attempt is still an
	 * exact filename match, never fuzzy - this only widens which exact
	 * filename gets tried, not how loosely each one matches.
	 *
	 * Returns null - leaving the citation's original CustomGPT url (and
	 * title) untouched - if nothing matches at all, or the title
	 * doesn't look like a real filename. On a match, returns an array
	 * with both the local 'url' and the 'filename' that actually
	 * matched - the caller uses the latter to correct the displayed
	 * title too when a fallback extension is what matched (e.g. a
	 * citation titled "CFOs-Budget-Playbook.pdf" that only matched
	 * "CFOs-Budget-Playbook.pptx" in the media library should say
	 * ".pptx" in the widget too, not still claim to be the PDF).
	 */
	private function find_local_media_url_by_filename( $filename ) {
		$filename = trim( (string) $filename );
		if ( '' === $filename || false === strpos( $filename, '.' ) ) {
			return null;
		}

		$url = $this->find_attachment_url_by_exact_filename( $filename );
		if ( $url ) {
			return array(
				'url'      => $url,
				'filename' => $filename,
			);
		}

		$dot          = strrpos( $filename, '.' );
		$base_name    = substr( $filename, 0, $dot );
		$original_ext = strtolower( substr( $filename, $dot + 1 ) );

		foreach ( array( 'pdf', 'pptx', 'ppt', 'docx', 'doc' ) as $fallback_ext ) {
			if ( $fallback_ext === $original_ext ) {
				continue; // Already tried above.
			}
			$candidate_filename = $base_name . '.' . $fallback_ext;
			$url                = $this->find_attachment_url_by_exact_filename( $candidate_filename );
			if ( $url ) {
				return array(
					'url'      => $url,
					'filename' => $candidate_filename,
				);
			}
		}

		return null;
	}

	/**
	 * Exact-filename half of find_local_media_url_by_filename() above -
	 * matches the _wp_attached_file postmeta (the real on-disk
	 * filename, e.g. "2026/08/CIO-Persona-Profile-v.2.pdf") rather than
	 * the post title, since WordPress sanitizes/spaces-to-dashes post
	 * titles on upload but keeps the real filename intact.
	 *
	 * Matches only on a real path boundary - either the whole stored
	 * value (a file uploaded with no year/month subfolder) or right
	 * after a "/" (e.g. "2026/08/<filename>") - not just any suffix. A
	 * plain "%filename" LIKE would also match an unrelated, longer
	 * filename that merely happens to end with this one (e.g.
	 * "XYZ-CIO-Persona-Profile-v.2.pdf").
	 *
	 * If more than one attachment still matches (the same filename
	 * genuinely uploaded more than once, e.g. into different month
	 * folders), the most recently uploaded one wins - WordPress already
	 * renames on collision instead of silently overwriting, so two
	 * distinct attachments sharing an exact filename are either an
	 * intentional re-upload/replacement (newest is correct) or a
	 * coincidence (arbitrary either way, and newest is the least
	 * surprising default).
	 */
	private function find_attachment_url_by_exact_filename( $filename ) {
		global $wpdb;

		$escaped       = $wpdb->esc_like( $filename );
		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = '_wp_attached_file'
				AND ( meta_value = %s OR meta_value LIKE %s )
				ORDER BY post_id DESC LIMIT 1",
				$filename,
				'%/' . $escaped
			)
		);

		if ( empty( $attachment_id ) ) {
			return null;
		}

		$url = wp_get_attachment_url( (int) $attachment_id );
		return $url ? $url : null;
	}

	/**
	 * Office documents (PowerPoint/Word) don't render natively in a
	 * browser tab the way a PDF does - opening the raw file URL would
	 * just force a download instead of letting the visitor view it,
	 * same problem this whole citation-rewriting feature exists to
	 * avoid. There's no practical way to convert these to PDF
	 * server-side here (no LibreOffice or equivalent on typical
	 * WordPress hosting, and even where available a real conversion
	 * takes several seconds and would need its own caching layer) - so
	 * instead this routes the URL through Microsoft's free, public
	 * Office Online viewer, which renders the file inline in the
	 * browser given just its public URL. No conversion, no caching, no
	 * extra infrastructure - only changes behavior for the non-PDF
	 * fallback formats; an actual PDF match is returned as-is, since
	 * browsers already render those natively.
	 *
	 * Requires the file's URL to be reachable from the public internet
	 * (Microsoft's servers have to be able to fetch it) - won't work if
	 * this site sits behind an IP allowlist, HTTP Basic Auth, or
	 * similar staging-only access restriction.
	 */
	private function maybe_wrap_in_office_viewer( $url, $filename ) {
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'pptx', 'ppt', 'docx', 'doc' ), true ) ) {
			return $url;
		}
		return 'https://view.officeapps.live.com/op/view.aspx?src=' . rawurlencode( $url );
	}

	/**
	 * Fetches a single citation's details - a small, ordinary JSON
	 * response, not a stream - and, when its title matches a filename
	 * in this site's media library, rewrites the "url" field so the
	 * widget's "View source" link points at that local, publicly
	 * downloadable file instead of CustomGPT's own account-gated
	 * preview URL (see find_local_media_url_by_filename() above for
	 * why: anonymous visitors get a 403 on the original URL).
	 *
	 * Deliberately separate from handle_proxy()'s streaming machinery
	 * below - this response needs to be parsed and possibly modified
	 * as a whole, which the character-by-character curl passthrough
	 * used for the chat endpoint isn't set up for, and doesn't need to
	 * be: unlike chat, there's no "typing" effect to preserve here.
	 */
	private function proxy_citation_request( $url, $api_key ) {
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Accept'        => 'application/json',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			status_header( 502 );
			header( 'Content-Type: application/json' );
			echo wp_json_encode(
				array(
					'error'   => 'Proxy request failed',
					'details' => $response->get_error_message(),
				)
			);
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code && is_array( $body ) && ! empty( $body['data'] ) && is_array( $body['data'] ) && ! empty( $body['data']['title'] ) ) {
			$original_title = (string) $body['data']['title'];
			$match          = $this->find_local_media_url_by_filename( $original_title );
			if ( $match ) {
				$body['data']['url'] = $this->maybe_wrap_in_office_viewer( $match['url'], $match['filename'] );
				// Reflect the matched file's actual extension in the
				// displayed title too, so a citation that only matched
				// via the fallback extensions above (e.g. found a
				// .pptx for a citation titled ".pdf") doesn't show a
				// filename that doesn't match what actually opens.
				if ( $match['filename'] !== $original_title ) {
					$body['data']['title'] = $match['filename'];
				}
			}
		}

		status_header( $code );
		header( 'Content-Type: application/json' );
		echo wp_json_encode( $body );
	}

	/**
	 * Serves GET /projects/{id}/settings from a short-lived transient
	 * cache when one exists, instead of always round-tripping to
	 * CustomGPT's API. This is the same cache fetch_agent_settings_cached()
	 * already warms on every page load (for the SSR hero placeholder) -
	 * this method is what lets the widget bundle's OWN separate settings
	 * fetch (part of its chat-initialization flow, not the hero display)
	 * benefit from that same warm cache instead of always paying its own
	 * full round trip on top of WordPress's admin-ajax bootstrap cost.
	 * Falls back to a live, uncached fetch/passthrough exactly like
	 * before whenever the cache is empty or expired.
	 */
	private function proxy_settings_request( $url, $api_key, $project_id ) {
		$cache_key = 'customgpt_widget_raw_settings_' . $project_id;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['body'] ) ) {
			status_header( 200 );
			header( 'Content-Type: application/json' );
			echo $cached['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw upstream API response, cached verbatim from a prior passthrough.
			return;
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Accept'        => 'application/json',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			status_header( 502 );
			header( 'Content-Type: application/json' );
			echo wp_json_encode(
				array(
					'error'   => 'Proxy request failed',
					'details' => $response->get_error_message(),
				)
			);
			return;
		}

		$code      = (int) wp_remote_retrieve_response_code( $response );
		$body_text = wp_remote_retrieve_body( $response );

		if ( 200 === $code && '' !== $body_text ) {
			set_transient( $cache_key, array( 'body' => $body_text ), 5 * MINUTE_IN_SECONDS );
		}

		status_header( $code );
		header( 'Content-Type: application/json' );
		echo $body_text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw upstream API passthrough.
	}

	/**
	 * Server-side proxy. Forwards the widget's API calls to
	 * app.customgpt.ai with the real API key attached, and streams the
	 * response straight back (needed for the chat "typing" SSE stream).
	 * The key never leaves the server.
	 */
	public function handle_proxy() {
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			status_header( 500 );
			header( 'Content-Type: application/json' );
			echo wp_json_encode( array( 'error' => 'CustomGPT API key is not configured. Set it under Settings -> CustomGPT Chat Widget.' ) );
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public proxy endpoint, no user state involved.
		$path = isset( $_GET['path'] ) ? wp_unslash( $_GET['path'] ) : '';
		$path = '/' . ltrim( (string) $path, '/' );

		// The widget bundle has two internal HTTP clients. The one used
		// for chat/conversations concatenates apiBaseUrl + endpoint
		// directly. The one used only for agent display info (name,
		// avatar, example questions) always prepends "/api/proxy" first
		// (it was written for the starter-kit's own Next.js app, which
		// has a route at that path). Strip it here so both conventions
		// resolve to the same real CustomGPT endpoint — otherwise every
		// agent-details fetch 404s and the widget silently falls back to
		// generic "Agent {id}" text and default example questions.
		if ( 0 === strpos( $path, '/api/proxy' ) ) {
			$path = substr( $path, strlen( '/api/proxy' ) );
			if ( '' === $path ) {
				$path = '/';
			}
		}

		// A literal "?" can end up inside $_GET['path'] if the widget
		// appended its own query string; split it back out.
		$extra_query = '';
		if ( false !== strpos( $path, '?' ) ) {
			list( $path, $extra_query ) = explode( '?', $path, 2 );
		}

		if ( '/' === $path || '' === trim( $path, '/' ) ) {
			status_header( 400 );
			header( 'Content-Type: application/json' );
			echo wp_json_encode( array( 'error' => 'Missing proxy path.' ) );
			exit;
		}

		$url = CUSTOMGPT_API_BASE . $path;
		if ( $extra_query ) {
			$url .= '?' . $extra_query;
		}

		// Citation "View source" links need to work for anonymous site
		// visitors too, but CustomGPT's own preview URLs
		// (https://app.customgpt.ai/preview/...) require a logged-in
		// CustomGPT account - a visitor without one gets a 403 there.
		// This one specific, non-streaming endpoint is special-cased so
		// its "url" can be swapped for a matching local media-library
		// file when one exists; everything else (in particular the
		// streaming chat endpoint below) is completely untouched.
		if (
			preg_match( '#^/projects/\d+/citations/\d+$#', $path )
			&& isset( $_SERVER['REQUEST_METHOD'] )
			&& 'GET' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
		) {
			$this->proxy_citation_request( $url, $api_key );
			exit;
		}

		// GET /projects/{id}/settings is fetched by the widget bundle's
		// own chat-initialization code path (separate from the SSR hero
		// prefetch in fetch_agent_settings_cached(), which now also warms
		// this same cache - see the comment there). Settings data changes
		// rarely, so serving it from a short-lived cache when possible
		// avoids paying a real CustomGPT API round trip on top of
		// WordPress's own admin-ajax bootstrap cost, every single time.
		// Only intercepts this one specific, non-streaming GET endpoint;
		// everything else (in particular the streaming chat endpoint
		// below) is completely untouched.
		if (
			preg_match( '#^/projects/(\d+)/settings$#', $path, $settings_match )
			&& isset( $_SERVER['REQUEST_METHOD'] )
			&& 'GET' === strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) )
		) {
			$this->proxy_settings_request( $url, $api_key, (int) $settings_match[1] );
			exit;
		}

		$method   = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$raw_body = file_get_contents( 'php://input' );

		$content_type = isset( $_SERVER['CONTENT_TYPE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) ) : 'application/json';

		$headers = array(
			'Authorization: Bearer ' . $api_key,
			'Accept: application/json, text/event-stream',
		);
		if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$headers[] = 'Content-Type: ' . $content_type;
		}

		/*
		 * Disable every layer of output buffering we can reach from PHP,
		 * so streamed chunks reach the browser as they arrive instead of
		 * being held until the whole response is done. This is the
		 * usual reason a "streaming" proxy silently behaves like a
		 * regular request: PHP's own zlib/gzip output compression (which
		 * MUST buffer the entire output before it can compress it),
		 * leftover ob_start() buffers from other WordPress plugins, or
		 * an in-front reverse proxy/CDN buffering by default.
		 *
		 * If chunks still arrive all at once after this, the buffering
		 * is happening somewhere outside PHP entirely - most likely a
		 * CDN (e.g. Cloudflare) or the host's own reverse proxy in front
		 * of PHP-FPM/nginx - and needs to be disabled there for this
		 * admin-ajax.php endpoint specifically.
		 */
		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		@ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
		@ini_set( 'output_buffering', 'off' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
		@ini_set( 'implicit_flush', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
		if ( function_exists( 'apache_setenv' ) === false ) {
			// Also try turning off zlib compression the non-ini_set way,
			// in case it was enabled via php.ini with PHP_INI_SYSTEM
			// scope (ini_set alone can't override that).
			if ( function_exists( 'ini_get' ) && ini_get( 'zlib.output_compression' ) ) {
				@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
			}
		}
		while ( ob_get_level() > 0 ) {
			@ob_end_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		set_time_limit( 0 );
		header( 'X-Accel-Buffering: no' ); // Tell nginx (direct or via a CDN that honours it) not to buffer this response.
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );

		$headers_sent = false;
		$sse_padded   = false;

		$ch = curl_init( $url );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_CUSTOMREQUEST  => $method,
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_TIMEOUT        => 120,
				CURLOPT_HEADERFUNCTION => function ( $curl_handle, $header_line ) use ( &$headers_sent, &$sse_padded ) {
					if ( 0 === stripos( $header_line, 'HTTP/' ) && preg_match( '#HTTP/\S+\s+(\d+)#', $header_line, $m ) ) {
						status_header( (int) $m[1] );
					}

					if ( 0 === stripos( $header_line, 'content-type:' ) ) {
						header( trim( $header_line ) );
						if ( false !== stripos( $header_line, 'text/event-stream' ) ) {
							header( 'Cache-Control: no-cache' );
							header( 'Connection: keep-alive' );

							// Only now do we know for certain the upstream
							// is actually sending SSE, so it's safe to pad:
							// some buffering layers (proxies, occasionally
							// browsers) hold onto the first response chunk
							// until a minimum byte threshold is reached.
							// An SSE comment line (leading colon) is inert
							// to any spec-compliant client.
							if ( ! $sse_padded ) {
								echo ':' . str_repeat( ' ', 2048 ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SSE comment padding, not user data.
								flush();
								$sse_padded = true;
							}
						}
						$headers_sent = true;
					}

					return strlen( $header_line );
				},
				CURLOPT_WRITEFUNCTION  => function ( $curl_handle, $chunk ) {
					echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw upstream API passthrough (JSON / SSE).
					flush();
					return strlen( $chunk );
				},
			)
		);

		if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) && '' !== $raw_body ) {
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $raw_body );
		}

		// Diagnostic only: splits "how long did WordPress itself take to
		// get here" from "how long did the upstream CustomGPT API take" -
		// the two very different possible explanations for a slow proxy
		// call. REQUEST_TIME_FLOAT is set by PHP at the very start of the
		// request, before admin-ajax.php has loaded WordPress core, every
		// active plugin, and the theme - all of which run before this
		// function is ever called - so this header captures that entire
		// cost, not just time spent inside this method. Cheap enough
		// (one subtraction) to leave in permanently rather than gate
		// behind a flag; safe to remove once the current investigation is
		// done if it's no longer useful.
		if ( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
			header( 'X-CGPT-Bootstrap-Ms: ' . round( ( microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000 ) );
		}

		curl_exec( $ch );

		if ( curl_errno( $ch ) && ! $headers_sent ) {
			status_header( 502 );
			header( 'Content-Type: application/json' );
			echo wp_json_encode(
				array(
					'error'   => 'Proxy request failed',
					'details' => curl_error( $ch ),
				)
			);
		}

		curl_close( $ch );
		exit;
	}
}

new CustomGPT_Chat_Widget_Plugin();
