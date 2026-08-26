<?php
/**
 * CustomGPT Chat Widget - fast-path proxy accelerator.
 *
 * This file is NOT loaded as part of the normal plugin bootstrap. It is
 * copied into wp-content/mu-plugins/ on plugin activation (see
 * install_fast_proxy() in customgpt-chat-widget.php) so it runs at the
 * "must-use plugins" stage of WordPress's own bootstrap - before every
 * OTHER active plugin, and before the theme, get loaded. Measured live on
 * this site: a normal proxy request going through wp-admin/admin-ajax.php
 * (full WP bootstrap - every other active plugin, the theme's
 * functions.php, admin-ajax.php's own admin-context loading) costs
 * roughly 1.4 SECONDS before this plugin's own proxy code ever runs, on
 * top of whatever the actual upstream CustomGPT API call takes. That cost
 * is paid on every single message in a conversation, not just the first
 * one, since neither "create a conversation" nor "send a message" can be
 * cached (each is genuinely new content).
 *
 * This file exists to skip that 1.4s tax specifically for those two
 * endpoints, by handling them here - this early, before anything slow
 * has had a chance to load - and exiting immediately, so WordPress never
 * proceeds to load every other plugin/theme for these two specific
 * requests at all.
 *
 * Deliberately narrow in scope: this ONLY intercepts
 *   POST /projects/{id}/conversations
 *   POST /projects/{id}/conversations/{id}/messages
 * via the customgpt_proxy admin-ajax action. Every other path (settings,
 * citations, anything unrecognised) - and these same two paths whenever
 * anything below looks even slightly off (missing config, wrong method,
 * fast-path disabled in Settings) - falls straight through by simply
 * returning without exiting, letting WordPress continue its completely
 * normal bootstrap and reach this plugin's existing, fully-featured
 * handle_proxy() exactly as before. Correctness always lives in that
 * normal path; this file is purely an optional accelerator layered on
 * top of it, never a replacement for it.
 *
 * Safe to delete this file (or deactivate the plugin, which removes it
 * automatically) at any time - nothing else depends on its presence.
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

// Defensive: if get_option() somehow isn't available yet on some
// unusual hosting setup, bail out immediately rather than risk a hard
// PHP fatal error from calling an undefined function later in this
// file - a mu-plugin fatal takes down the ENTIRE site, not just this
// feature, so this file must never assume anything about WordPress's
// own bootstrap beyond what's explicitly checked here.
if ( ! function_exists( 'get_option' ) ) {
	return;
}

// Cheapest possible check first, so the overhead added to every OTHER
// request on the entire site (every normal page view, every unrelated
// admin-ajax action) is a single string comparison.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check, no state changes here.
if ( ! isset( $_GET['action'] ) || 'customgpt_proxy' !== $_GET['action'] ) {
	return;
}

// Confirm this really is an admin-ajax.php request and not some other
// context that happens to share a query var name - SCRIPT_NAME is set
// this early in every tested environment (Apache mod_php, PHP-FPM via
// nginx/Apache) since it comes from the web server, not from WordPress.
if (
	! isset( $_SERVER['SCRIPT_NAME'] )
	|| '/admin-ajax.php' !== substr( (string) $_SERVER['SCRIPT_NAME'], -15 )
) {
	return;
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public proxy endpoint, matches handle_proxy()'s own existing behaviour.
$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
if ( 'POST' !== $method ) {
	return;
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
$raw_path = isset( $_GET['path'] ) ? (string) $_GET['path'] : '';
$path     = '/' . ltrim( $raw_path, '/' );
if ( 0 === strpos( $path, '/api/proxy' ) ) {
	$path = substr( $path, strlen( '/api/proxy' ) );
}
if ( false !== strpos( $path, '?' ) ) {
	$path = explode( '?', $path, 2 )[0];
}

$is_create_conversation = (bool) preg_match( '#^/projects/\d+/conversations$#', $path );
$is_send_message        = (bool) preg_match( '#^/projects/\d+/conversations/[^/]+/messages$#', $path );
if ( ! $is_create_conversation && ! $is_send_message ) {
	return;
}

// From here on this is a real match. DB access (get_option) and the
// filesystem/curl are all safe to use at this point in WordPress's own
// bootstrap - $wpdb and the options API load well before must-use
// plugins do - but nothing from this plugin's own class exists yet
// (that only loads later, as a regular plugin), so every piece of logic
// needed is duplicated here directly rather than referencing it.

if ( '0' === get_option( 'customgpt_widget_fast_proxy_enabled', '1' ) ) {
	return;
}

$api_key = defined( 'CUSTOMGPT_WIDGET_API_KEY' ) && '' !== CUSTOMGPT_WIDGET_API_KEY
	? CUSTOMGPT_WIDGET_API_KEY
	: get_option( 'customgpt_widget_api_key', '' );
if ( empty( $api_key ) ) {
	// Let the normal path produce its usual "API key is not configured"
	// error rather than duplicating that message here.
	return;
}

if ( ! function_exists( 'curl_init' ) ) {
	return;
}

$api_base = defined( 'CUSTOMGPT_API_BASE' ) ? CUSTOMGPT_API_BASE : 'https://app.customgpt.ai/api/v1';
$url      = $api_base . $path;

$raw_body = file_get_contents( 'php://input' );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public proxy endpoint, matches handle_proxy()'s own existing behaviour.
$content_type = isset( $_SERVER['CONTENT_TYPE'] ) ? (string) $_SERVER['CONTENT_TYPE'] : 'application/json';

$headers = array(
	'Authorization: Bearer ' . $api_key,
	'Accept: application/json, text/event-stream',
	'Content-Type: ' . $content_type,
);

// Same streaming-safety measures as the normal handle_proxy() path -
// see the detailed comment there for why each of these matters.
if ( function_exists( 'apache_setenv' ) ) {
	@apache_setenv( 'no-gzip', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}
@ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
@ini_set( 'output_buffering', 'off' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
@ini_set( 'implicit_flush', '1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.IniSet.Risky
while ( ob_get_level() > 0 ) {
	@ob_end_flush(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}
if ( function_exists( 'set_time_limit' ) ) {
	@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}
header( 'X-Accel-Buffering: no' );
header( 'Cache-Control: no-cache, no-store, must-revalidate' );
// Purely diagnostic: confirms a given response actually went through
// this fast path, for verifying it's working / A-B comparing timing
// against the normal admin-ajax.php path. Safe to leave in permanently.
header( 'X-CGPT-Fast-Proxy: 1' );

$headers_sent = false;
$sse_padded   = false;

$ch = curl_init( $url );
curl_setopt_array(
	$ch,
	array(
		CURLOPT_CUSTOMREQUEST  => 'POST',
		CURLOPT_HTTPHEADER     => $headers,
		CURLOPT_TIMEOUT        => 120,
		CURLOPT_POSTFIELDS     => $raw_body,
		CURLOPT_HEADERFUNCTION => function ( $curl_handle, $header_line ) use ( &$headers_sent, &$sse_padded ) {
			if ( 0 === stripos( $header_line, 'HTTP/' ) && preg_match( '#HTTP/\S+\s+(\d+)#', $header_line, $m ) ) {
				http_response_code( (int) $m[1] );
			}
			if ( 0 === stripos( $header_line, 'content-type:' ) ) {
				header( trim( $header_line ) );
				if ( false !== stripos( $header_line, 'text/event-stream' ) ) {
					header( 'Cache-Control: no-cache' );
					header( 'Connection: keep-alive' );
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

curl_exec( $ch );

if ( curl_errno( $ch ) && ! $headers_sent ) {
	http_response_code( 502 );
	header( 'Content-Type: application/json' );
	// Native json_encode(), not wp_json_encode() - this file avoids any
	// WordPress function that isn't get_option()/curl, checked above, so
	// nothing here can hard-fatal even in edge-case bootstrap orderings.
	echo json_encode( array( 'error' => 'Proxy request failed', 'details' => curl_error( $ch ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

curl_close( $ch );
exit;
