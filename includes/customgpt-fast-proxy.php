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

// Per-logged-in-user "messages sent" usage tracking (see
// record_chat_message() in the main plugin file) needs
// get_current_user_id(), which depends on wp_get_current_user() in
// wp-includes/pluggable.php - not loaded yet at this mu-plugin stage
// (mu-plugins run before pluggable.php, precisely so plugins CAN
// override pluggable functions; see the big comment at the top of
// this file about fatal errors from assuming too much this early).
// Rather than hand-parse the WordPress auth cookie ourselves here -
// exactly the kind of fragile early-bootstrap logic this file exists
// to avoid - a "send message" request from a browser carrying a
// logged-in-user cookie simply bails out of the fast path and falls
// through to the normal, fully-booted handle_proxy(), where the user
// is known safely and the count stays accurate. This site's traffic
// is overwhelmingly anonymous visitors, who are completely unaffected
// and keep the fast path exactly as before.
if ( $is_send_message ) {
	foreach ( array_keys( $_COOKIE ) as $cgpt_cookie_name ) {
		if ( 0 === strpos( $cgpt_cookie_name, 'wordpress_logged_in_' ) ) {
			return;
		}
	}
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

$headers_sent    = false;
$sse_padded      = false;
$response_status = 0;

// Mirrors the equivalent fallback in the normal handle_proxy() path
// (customgpt-chat-widget.php): some CustomGPT agents have "Plan & Act"
// mode turned on, where POST .../messages always 400s with a small,
// non-streamed JSON body telling the caller to use .../tasks instead.
// $is_event_stream/$plan_act_buffer hold that one specific response
// back just long enough to check it, rather than relaying the error
// straight through to the widget.
$is_event_stream = false;
$plan_act_buffer = '';

$ch = curl_init( $url );
curl_setopt_array(
	$ch,
	array(
		CURLOPT_CUSTOMREQUEST  => 'POST',
		CURLOPT_HTTPHEADER     => $headers,
		CURLOPT_TIMEOUT        => 120,
		CURLOPT_POSTFIELDS     => $raw_body,
		CURLOPT_HEADERFUNCTION => function ( $curl_handle, $header_line ) use ( &$headers_sent, &$sse_padded, &$response_status, &$is_event_stream ) {
			if ( 0 === stripos( $header_line, 'HTTP/' ) && preg_match( '#HTTP/\S+\s+(\d+)#', $header_line, $m ) ) {
				$response_status = (int) $m[1];
				http_response_code( $response_status );
			}
			if ( 0 === stripos( $header_line, 'content-type:' ) ) {
				header( trim( $header_line ) );
				if ( false !== stripos( $header_line, 'text/event-stream' ) ) {
					$is_event_stream = true;
					header( 'Cache-Control: no-cache' );
					header( 'Connection: keep-alive' );
					// Repeated spaces compress down to almost nothing
					// under gzip, so they never fill nginx's own gzip
					// output buffer - confirmed live, this is why the
					// whole SSE answer was arriving as one chunk instead
					// of streaming, regardless of the anti-buffering
					// settings above (those are PHP-level; nginx's gzip
					// module is not). Random bytes compress at roughly
					// 1:1, so 64KB of them forces an early flush under
					// most default nginx gzip_buffers configs without
					// needing server access. Real fix is still excluding
					// text/event-stream from gzip_types at the nginx
					// level - this is a best-effort mitigation for when
					// that isn't available yet.
					if ( ! $sse_padded ) {
						echo ':' . base64_encode( random_bytes( 65536 ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SSE comment padding, not user data.
						flush();
						$sse_padded = true;
					}
				}
				$headers_sent = true;
			}
			return strlen( $header_line );
		},
		CURLOPT_WRITEFUNCTION  => function ( $curl_handle, $chunk ) use ( &$plan_act_buffer, &$response_status, &$is_event_stream, $is_send_message ) {
			if ( $is_send_message && ! $is_event_stream && 400 === $response_status ) {
				$plan_act_buffer .= $chunk;
				return strlen( $chunk );
			}
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

// Transparent "Plan & Act" fallback. Mirrors
// CustomGPT_Chat_Widget_Plugin::run_plan_act_task() in the main plugin
// file (customgpt-chat-widget.php) - see that method's own docblock for
// the full explanation of why Plan & Act needs a real task-creation +
// polling flow rather than a simple endpoint swap, confirmed live
// against the STAGING agent before either copy of this was written.
// Duplicated here as a plain function, not a call into the class,
// because nothing from this plugin's class exists yet at this mu-plugin
// stage (see the file-level comment at the top of this file).
if ( $is_send_message && '' !== $plan_act_buffer ) {
	$decoded_error     = json_decode( $plan_act_buffer, true );
	$is_plan_act_error = is_array( $decoded_error )
		&& isset( $decoded_error['data']['message'] )
		&& false !== stripos( (string) $decoded_error['data']['message'], 'Plan & Act' )
		&& false !== stripos( (string) $decoded_error['data']['message'], '/tasks' );

	if ( $is_plan_act_error && preg_match( '#^/projects/(\d+)/#', $path, $project_match ) ) {
		$decoded_request = json_decode( (string) $raw_body, true );
		$prompt          = is_array( $decoded_request ) && isset( $decoded_request['prompt'] ) ? (string) $decoded_request['prompt'] : '';

		customgpt_fast_proxy_run_plan_act_task( (int) $project_match[1], $prompt, $api_key, $api_base );
	} else {
		// Not the Plan & Act case after all (or the path didn't contain
		// a project id, which should never happen for a send-message
		// call) - relay the buffered error through exactly as the
		// non-buffered path always has.
		echo $plan_act_buffer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw upstream API passthrough (JSON).
		flush();
	}
}

curl_close( $ch );
exit;

/**
 * See CustomGPT_Chat_Widget_Plugin::run_plan_act_task() in
 * customgpt-chat-widget.php for the full explanation - this is the same
 * logic, duplicated as a plain function for this early mu-plugin stage
 * (no class available yet). Translates CustomGPT's real, poll-based
 * Plan & Act task API into the small SSE vocabulary the widget bundle's
 * own stream parser understands (data: {"content"/"citations":...}
 * chunks, ended by a literal "event: finish" line).
 *
 * @param int    $project_id CustomGPT agent/project id.
 * @param string $prompt     The user's message text.
 * @param string $api_key    CustomGPT API key.
 * @param string $api_base   CUSTOMGPT_API_BASE (passed in rather than
 *                           read as a constant here, matching this
 *                           file's existing $api_base variable above).
 * @return bool True if the task reached "completed" and produced a reply.
 */
function customgpt_fast_proxy_run_plan_act_task( $project_id, $prompt, $api_key, $api_base ) {
	// See the matching comment in run_plan_act_task() in the main plugin
	// file - the original /messages call's header callback already set
	// the HTTP status to 400 before we knew we would end up here, and
	// without resetting it the widget bundle's response.ok check would
	// discard this valid reply unread.
	http_response_code( 200 );
	header( 'Content-Type: text/event-stream' );
	header( 'Cache-Control: no-cache' );
	header( 'Connection: keep-alive' );
	echo ':' . base64_encode( random_bytes( 65536 ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SSE comment padding, not user data.
	flush();

	$send_chunk = function ( $payload ) {
		// Native json_encode(), not wp_json_encode() - see the file-level
		// comment at the top of this file on why this file avoids
		// WordPress functions this early.
		echo 'data: ' . json_encode( $payload ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		flush();
	};
	$send_finish = function () {
		echo "event: finish\ndata:\n\n";
		flush();
	};

	$create_ch = curl_init( $api_base . '/projects/' . $project_id . '/tasks' );
	curl_setopt_array(
		$create_ch,
		array(
			CURLOPT_CUSTOMREQUEST  => 'POST',
			CURLOPT_HTTPHEADER     => array(
				'Authorization: Bearer ' . $api_key,
				'Accept: application/json',
				'Content-Type: application/json',
			),
			CURLOPT_POSTFIELDS     => json_encode( array( 'prompt' => $prompt ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 30,
		)
	);
	$create_response = curl_exec( $create_ch );
	$create_errno     = curl_errno( $create_ch );
	curl_close( $create_ch );

	$task_id = null;
	if ( ! $create_errno && is_string( $create_response ) ) {
		$decoded_create = json_decode( $create_response, true );
		$task_id        = $decoded_create['data']['id'] ?? null;
	}

	if ( empty( $task_id ) ) {
		$send_chunk( array( 'content' => "Sorry, I couldn't start that task just now. Please try again in a moment." ) );
		$send_finish();
		return false;
	}

	$poll_url         = $api_base . '/projects/' . $project_id . '/tasks/' . rawurlencode( (string) $task_id );
	$poll_headers     = array(
		'Authorization: Bearer ' . $api_key,
		'Accept: application/json',
	);
	$any_content_sent = false;
	$citations        = array();
	$succeeded        = false;
	$task_status      = null;
	$deadline         = time() + 170;

	while ( time() < $deadline ) {
		$poll_ch = curl_init( $poll_url );
		curl_setopt_array(
			$poll_ch,
			array(
				CURLOPT_HTTPHEADER     => $poll_headers,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 15,
			)
		);
		$poll_response = curl_exec( $poll_ch );
		$poll_errno    = curl_errno( $poll_ch );
		curl_close( $poll_ch );

		if ( $poll_errno || ! is_string( $poll_response ) ) {
			usleep( 1500000 );
			continue;
		}

		$decoded_poll = json_decode( $poll_response, true );
		$task_status  = $decoded_poll['data']['status'] ?? null;
		$events       = $decoded_poll['data']['events'] ?? array();

		if ( is_array( $events ) ) {
			foreach ( $events as $event ) {
				$event_type = $event['type'] ?? '';
				$event_data = $event['data'] ?? array();

				if ( 'progress' === $event_type && '' !== (string) ( $event_data['message'] ?? '' ) ) {
					$send_chunk( array( 'content' => (string) $event_data['message'] ) );
					$any_content_sent = true;
				} elseif ( 'finish' === $event_type ) {
					$citations = is_array( $event_data['citations'] ?? null ) ? $event_data['citations'] : array();
					if ( ! $any_content_sent && '' !== (string) ( $event_data['response'] ?? '' ) ) {
						$send_chunk( array( 'content' => (string) $event_data['response'] ) );
						$any_content_sent = true;
					}
				}
			}
		}

		if ( 'completed' === $task_status ) {
			$succeeded = true;
			break;
		}

		if ( 'failed' === $task_status ) {
			if ( ! $any_content_sent ) {
				$send_chunk( array( 'content' => "Sorry, I wasn't able to complete that request. Please try again." ) );
			}
			break;
		}

		if ( 'awaiting_tool_results' === $task_status ) {
			$send_chunk( array( 'content' => 'Sorry, this request needs a tool this chat widget doesn\'t support yet.' ) );
			break;
		}

		usleep( 1500000 );
	}

	if ( ! $succeeded && 'completed' !== $task_status && time() >= $deadline ) {
		$send_chunk( array( 'content' => 'Sorry, this is taking longer than expected. Please try again.' ) );
	}

	if ( ! empty( $citations ) ) {
		$send_chunk( array( 'citations' => $citations ) );
	}

	$send_finish();

	return $succeeded;
}
