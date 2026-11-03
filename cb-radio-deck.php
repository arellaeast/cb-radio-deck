<?php
/**
 * Plugin Name: Captain Baltimore Radio Deck
 * Description: Redesigned Pirate Radio player for /radio/ — channel cards with live tracks on every channel, progress bar, recently played, sticky mini-bar. Front-end only: reuses the cb-pirate-radio REST backend (now-playing, votes, charts) and merges AzuraCast progress/history server-side.
 * Version: 1.1.8
 * Author: Lisa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CB_DECK_VERSION', '1.1.8' );

// How old stored state may get before a read asks for a background refresh.
define( 'CB_DECK_STALE_AFTER', 90 );

/**
 * Channel map: slug => label / freq / AzuraCast station shortcode / accent.
 * Streams come from the cb-radio now-playing endpoint at runtime.
 */
function cb_deck_channels() {
	return array(
		'drill' => array(
			'label'   => 'Drill',
			'freq'    => '87.90',
			'station' => 'captainbaltimore',
			'accent'  => '#FF2E88',
		),
		'hip-hop-and-r-and-b' => array(
			'label'   => 'Hip-Hop and R&B',
			'freq'    => '92.10',
			'station' => 'local_baltimore_hip_hop_and_rb',
			'accent'  => '#7B2FFF',
		),
		'bmore-club2'         => array(
			'label'   => 'Rock, Punk and Alternative',
			'freq'    => '93.30',
			'station' => 'local_baltimore_pop_rock_and_alternative',
			'accent'  => '#00C2E8',
		),
		'dock-talk'           => array(
			'label'   => 'Dock Talk',
			'freq'    => '94.10',
			'station' => 'dock_talk',
			'accent'  => '#FFD500',
		),
		'247-rock-off'        => array(
			'label'   => '24/7 Rock Off',
			'freq'    => '96.50',
			'station' => '247_rock_off',
			'accent'  => '#7ED321',
		),
		'jazz'                => array(
			'label'   => 'Jazz',
			'freq'    => '98.10',
			'station' => 'jazz',
			'accent'  => '#7B2FFF',
		),
		'country'             => array(
			'label'   => 'Country',
			'freq'    => '100.50',
			'station' => 'country',
			'accent'  => '#FFD500',
		),
		'gospel'              => array(
			'label'   => 'Gospel',
			'freq'    => '102.10',
			'station' => 'gospel',
			'accent'  => '#FF2E88',
		),
	);
}

/** Takeover gate: LIVE for everyone since v1.0.4 (Jacob approved 2026-10-23). ?deck=old is the escape hatch back to the legacy tuner. */
function cb_deck_enabled() {
	if ( isset( $_GET['deck'] ) && 'old' === $_GET['deck'] ) {
		return false;
	}
	return true;
}

/** Make the preview sticky per-browser; ?deck=old clears it. */
add_action( 'init', 'cb_deck_preview_cookie' );
function cb_deck_preview_cookie() {
	if ( ! isset( $_GET['deck'] ) ) {
		return;
	}
	if ( 'v2' === $_GET['deck'] && empty( $_COOKIE['cb_deck_preview'] ) ) {
		setcookie( 'cb_deck_preview', '1', time() + 30 * DAY_IN_SECONDS, '/' );
		$_COOKIE['cb_deck_preview'] = '1';
	} elseif ( 'old' === $_GET['deck'] && ! empty( $_COOKIE['cb_deck_preview'] ) ) {
		setcookie( 'cb_deck_preview', '', time() - HOUR_IN_SECONDS, '/' );
		unset( $_COOKIE['cb_deck_preview'] );
	}
}

/** URL of the static state snapshot the background refresh maintains. */
function cb_deck_snapshot_url() {
	$up = wp_upload_dir();
	if ( ! empty( $up['error'] ) || empty( $up['baseurl'] ) ) {
		return '';
	}
	return trailingslashit( $up['baseurl'] ) . 'cb-deck-state.json';
}

add_action( 'wp_enqueue_scripts', 'cb_deck_enqueue', 99 );
function cb_deck_enqueue() {
	if ( ! is_page( 'radio' ) || ! cb_deck_enabled() ) {
		return;
	}

	// The old tuner's front-end goes quiet; its backend (REST + shortcodes) stays.
	wp_dequeue_script( 'cb-radio-tuner' );
	wp_dequeue_script( 'cb-radio-hlsjs' );
	wp_dequeue_style( 'cb-radio-tuner' );

	$base = plugin_dir_url( __FILE__ );
	wp_enqueue_style( 'cb-deck', $base . 'assets/deck.css', array(), CB_DECK_VERSION );
	wp_enqueue_script( 'cb-deck', $base . 'assets/deck.js', array(), CB_DECK_VERSION, true );

	$channels = array();
	foreach ( cb_deck_channels() as $slug => $c ) {
		$channels[] = array(
			'slug'   => $slug,
			'label'  => $c['label'],
			'freq'   => $c['freq'],
			'accent' => $c['accent'],
		);
	}

	$rest_state = rest_url( 'cb-deck/v1/state' );
	$snapshot   = cb_deck_snapshot_url();

	$config = array(
		// Browsers poll the static snapshot (served by nginx, no PHP worker);
		// the REST endpoint is the fallback and returns the same stored data.
		'stateUrl'     => '' !== $snapshot ? $snapshot : $rest_state,
		'stateRestUrl' => $rest_state,
		'chartBase'    => rest_url( 'cb-radio/v1/charts/' ),
		'voteUrl'      => rest_url( 'cb-radio/v1/vote' ),
		'myVoteUrl'    => rest_url( 'cb-radio/v1/my-vote/' ),
		'nonce'        => wp_create_nonce( 'wp_rest' ),
		'poll'         => 15000,
		'channels'     => $channels,
	);
	wp_add_inline_script( 'cb-deck', 'window.CB_DECK = ' . wp_json_encode( $config ) . ';', 'before' );
}

/**
 * The old plugin enqueues its tuner assets from inside its shortcode, which
 * runs during content rendering — AFTER wp_enqueue_scripts. So the dequeue
 * in cb_deck_enqueue() misses them; repeat it on wp_footer just before the
 * footer scripts/styles print (priority 20).
 */
add_action( 'wp_footer', 'cb_deck_late_dequeue', 1 );
function cb_deck_late_dequeue() {
	if ( ! is_page( 'radio' ) || ! cb_deck_enabled() ) {
		return;
	}
	wp_dequeue_script( 'cb-radio-tuner' );
	wp_dequeue_script( 'cb-radio-hlsjs' );
	wp_dequeue_style( 'cb-radio-tuner' );
}

add_action( 'rest_api_init', 'cb_deck_register_routes' );
function cb_deck_register_routes() {
	register_rest_route( 'cb-deck/v1', '/state', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => 'cb_deck_state',
	) );
}

/** Make an AzuraCast art path absolute if it arrives relative. */
function cb_deck_art_url( $art ) {
	if ( ! $art ) {
		return '';
	}
	if ( 0 === strpos( $art, '/' ) ) {
		return 'https://radio.captainbaltimore.com' . $art;
	}
	return $art;
}

/** AzuraCast listen URL for a station shortcode (skeleton state only). */
function cb_deck_stream_url( $station ) {
	return 'https://radio.captainbaltimore.com/listen/' . $station . '/radio.mp3';
}

/** Base URL for AzuraCast now-playing pulls. Filterable for staging tests. */
function cb_deck_azuracast_base() {
	return apply_filters( 'cb_deck_azuracast_base', 'https://radio.captainbaltimore.com/api/nowplaying/' );
}

/**
 * Skeleton state: every channel with its label, dial and stream, but no song
 * data. Served when no background refresh has landed yet, so a cold cache
 * never makes a visitor's request wait on the radio.
 */
function cb_deck_skeleton() {
	$out = array( 'channels' => array(), 'generated_at' => 0 );
	foreach ( cb_deck_channels() as $slug => $c ) {
		$out['channels'][] = array(
			'slug'         => $slug,
			'label'        => $c['label'],
			'freq'         => $c['freq'],
			'stream'       => cb_deck_stream_url( $c['station'] ),
			'live'         => false,
			'listeners'    => 0,
			'song'         => null,
			'artist'       => null,
			'submissionId' => 0,
			'elapsed'      => null,
			'duration'     => null,
			'history'      => array(),
			'demo'         => false,
		);
	}
	return $out;
}

/** The last state the background refresh stored, or null if there is none. */
function cb_deck_stored_state() {
	$stored = get_option( 'cb_deck_state_cache', null );
	if ( is_array( $stored ) && ! empty( $stored['channels'] ) ) {
		return $stored;
	}
	return null;
}

/**
 * Ask for a background refresh without blocking: queue the cron event and
 * kick WP-Cron's non-blocking loopback. A short lock keeps a busy page from
 * queueing the same refresh over and over.
 */
function cb_deck_request_refresh() {
	if ( false !== get_transient( 'cb_deck_refresh_queued' ) ) {
		return;
	}
	set_transient( 'cb_deck_refresh_queued', 1, 45 );
	if ( ! wp_next_scheduled( 'cb_deck_refresh' ) ) {
		wp_schedule_event( time() + 60, 'cb_deck_minute', 'cb_deck_refresh' );
	}
	wp_schedule_single_event( time(), 'cb_deck_refresh' );
	spawn_cron();
}

/**
 * Merged per-channel state, served ONLY from data the background refresh
 * stored. This handler never fetches anything itself: a slow or dead radio
 * can make the data old, never the page slow. Before v1.1.8 this handler
 * rebuilt the state in the visitor's request with up to 16 sequential
 * remote calls, which is what parked PHP workers during the 2026-11-02
 * outage.
 */
function cb_deck_state() {
	$stored = cb_deck_stored_state();
	if ( null === $stored ) {
		cb_deck_request_refresh();
		return rest_ensure_response( cb_deck_skeleton() );
	}
	$age = time() - (int) ( isset( $stored['generated_at'] ) ? $stored['generated_at'] : 0 );
	if ( $age > CB_DECK_STALE_AFTER ) {
		cb_deck_request_refresh();
	}
	return rest_ensure_response( $stored );
}

/* -------------------------------------------------------------------------
 * Background refresh (WP-Cron). This is the only place the deck talks to
 * AzuraCast or to the cb-radio now-playing proxy, and every call carries a
 * short timeout so a hung radio can never hold it for long either.
 * ---------------------------------------------------------------------- */

add_filter( 'cron_schedules', 'cb_deck_cron_schedules' );
function cb_deck_cron_schedules( $schedules ) {
	$schedules['cb_deck_minute'] = array(
		'interval' => 60,
		'display'  => 'Every minute (CB Deck state refresh)',
	);
	return $schedules;
}

add_action( 'init', 'cb_deck_ensure_cron' );
function cb_deck_ensure_cron() {
	if ( ! wp_next_scheduled( 'cb_deck_refresh' ) ) {
		wp_schedule_event( time() + 60, 'cb_deck_minute', 'cb_deck_refresh' );
	}
}

add_action( 'cb_deck_refresh', 'cb_deck_refresh_state' );

register_deactivation_hook( __FILE__, 'cb_deck_deactivate' );
function cb_deck_deactivate() {
	$ts = wp_next_scheduled( 'cb_deck_refresh' );
	if ( $ts ) {
		wp_unschedule_event( $ts, 'cb_deck_refresh' );
	}
}

/** Cap cURL connect time at 2s for every fetch the refresh makes. */
function cb_deck_curl_connect_timeout( $handle ) {
	curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 2 );
}

/** Write the state as a static JSON file the web server can serve directly. */
function cb_deck_write_snapshot( $payload ) {
	$up = wp_upload_dir();
	if ( ! empty( $up['error'] ) || empty( $up['basedir'] ) ) {
		return;
	}
	$json = wp_json_encode( $payload );
	if ( false === $json ) {
		return;
	}
	$path = trailingslashit( $up['basedir'] ) . 'cb-deck-state.json';
	$tmp  = $path . '.tmp';
	// Atomic swap: a poll mid-write always sees a complete file.
	if ( false !== file_put_contents( $tmp, $json ) ) {
		rename( $tmp, $path );
	}
}

/**
 * Rebuild the merged state from AzuraCast + the cb-radio proxy and store it.
 * Channels whose sources fail keep their last good data, so a bad minute at
 * the radio never blanks the deck.
 */
function cb_deck_refresh_state() {
	if ( false !== get_transient( 'cb_deck_refresh_lock' ) ) {
		return;
	}
	set_transient( 'cb_deck_refresh_lock', 1, 120 );

	$channels = cb_deck_channels();
	$prev     = cb_deck_stored_state();
	$prev_by  = array();
	if ( is_array( $prev ) && ! empty( $prev['channels'] ) ) {
		foreach ( $prev['channels'] as $p ) {
			if ( ! empty( $p['slug'] ) ) {
				$prev_by[ $p['slug'] ] = $p;
			}
		}
	}

	$out = array( 'channels' => array(), 'generated_at' => time() );

	add_action( 'http_api_curl', 'cb_deck_curl_connect_timeout', 10, 1 );

	// One AzuraCast pull per unique station.
	$az_base  = cb_deck_azuracast_base();
	$stations = array();
	foreach ( $channels as $c ) {
		$st = $c['station'];
		if ( array_key_exists( $st, $stations ) ) {
			continue;
		}
		$stations[ $st ] = null;
		$res = wp_remote_get( $az_base . $st, array( 'timeout' => 4 ) );
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$stations[ $st ] = json_decode( wp_remote_retrieve_body( $res ), true );
		}
	}

	foreach ( $channels as $slug => $c ) {
		$prev_item = isset( $prev_by[ $slug ] ) ? $prev_by[ $slug ] : null;

		$item = array(
			'slug'         => $slug,
			'label'        => $c['label'],
			'freq'         => $c['freq'],
			'stream'       => '',
			'live'         => false,
			'listeners'    => 0,
			'song'         => null,
			'artist'       => null,
			'submissionId' => 0,
			'elapsed'      => null,
			'duration'     => null,
			'history'      => array(),
			'demo'         => false,
		);

		$np_ok = false;
		$res   = wp_remote_get( rest_url( 'cb-radio/v1/now-playing/' . $slug ), array( 'timeout' => 4 ) );
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$np = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( is_array( $np ) ) {
				$np_ok                = true;
				$item['stream']       = isset( $np['stream_url'] ) ? $np['stream_url'] : '';
				$item['live']         = ! empty( $np['live'] );
				$item['listeners']    = isset( $np['listeners'] ) ? (int) $np['listeners'] : 0;
				$item['song']         = isset( $np['song'] ) ? $np['song'] : null;
				$item['artist']       = isset( $np['artist'] ) ? $np['artist'] : null;
				$item['submissionId'] = isset( $np['submission_id'] ) ? (int) $np['submission_id'] : 0;
				$item['demo']         = ! empty( $np['demo'] );
			}
		}

		$az = isset( $stations[ $c['station'] ] ) ? $stations[ $c['station'] ] : null;

		if ( ! $np_ok && is_array( $prev_item ) ) {
			// The proxy did not answer: keep showing the last good channel
			// state. Progress and history may still advance from AzuraCast,
			// but only when it is playing the same song we are displaying,
			// so the vote button always matches the track on screen.
			$item = $prev_item;
			if ( is_array( $az ) && ! empty( $az['now_playing'] ) ) {
				if ( isset( $az['listeners']['total'] ) ) {
					$item['listeners'] = (int) $az['listeners']['total'];
				}
				$az_title   = isset( $az['now_playing']['song']['title'] ) ? $az['now_playing']['song']['title'] : '';
				$item_title = isset( $item['song']['title'] ) ? $item['song']['title'] : '';
				if ( '' !== $az_title && $az_title === $item_title ) {
					if ( isset( $az['now_playing']['elapsed'] ) ) {
						$item['elapsed'] = (int) $az['now_playing']['elapsed'];
					}
					if ( isset( $az['now_playing']['duration'] ) ) {
						$item['duration'] = (int) $az['now_playing']['duration'];
					}
					if ( ! empty( $az['song_history'] ) && is_array( $az['song_history'] ) ) {
						$hist = array();
						foreach ( array_slice( $az['song_history'], 0, 5 ) as $h ) {
							if ( empty( $h['song'] ) ) {
								continue;
							}
							$hist[] = array(
								'title'    => isset( $h['song']['title'] ) ? $h['song']['title'] : '',
								'artist'   => isset( $h['song']['artist'] ) ? $h['song']['artist'] : '',
								'art'      => cb_deck_art_url( isset( $h['song']['art'] ) ? $h['song']['art'] : '' ),
								'playedAt' => isset( $h['played_at'] ) ? (int) $h['played_at'] : 0,
							);
						}
						$item['history'] = $hist;
					}
				}
			}
			$out['channels'][] = $item;
			continue;
		}

		if ( is_array( $az ) && ! empty( $az['now_playing'] ) ) {
			// Channels the cb-radio proxy doesn't know (e.g. Dock Talk, whose
			// station lives only in AzuraCast) fall back to AzuraCast for the
			// stream URL, listener count and on-air flag.
			if ( '' === $item['stream'] && ! empty( $az['station']['listen_url'] ) ) {
				$item['stream'] = $az['station']['listen_url'];
			}
			if ( 0 === $item['listeners'] && isset( $az['listeners']['total'] ) ) {
				$item['listeners'] = (int) $az['listeners']['total'];
			}
			if ( ! $item['live'] ) {
				$item['live'] = true;
			}
			$npz = $az['now_playing'];
			if ( isset( $npz['elapsed'] ) ) {
				$item['elapsed'] = (int) $npz['elapsed'];
			}
			if ( isset( $npz['duration'] ) ) {
				$item['duration'] = (int) $npz['duration'];
			}
			// The WP proxy is authoritative for what's on air; only fall back
			// to AzuraCast for the song if the proxy had nothing.
			if ( ! $item['song'] && ! empty( $npz['song'] ) ) {
				$item['song'] = array(
					'title'  => isset( $npz['song']['title'] ) ? $npz['song']['title'] : '',
					'artist' => isset( $npz['song']['artist'] ) ? $npz['song']['artist'] : '',
					'art'    => cb_deck_art_url( isset( $npz['song']['art'] ) ? $npz['song']['art'] : '' ),
				);
			}
			if ( ! empty( $az['song_history'] ) && is_array( $az['song_history'] ) ) {
				foreach ( array_slice( $az['song_history'], 0, 5 ) as $h ) {
					if ( empty( $h['song'] ) ) {
						continue;
					}
					$item['history'][] = array(
						'title'    => isset( $h['song']['title'] ) ? $h['song']['title'] : '',
						'artist'   => isset( $h['song']['artist'] ) ? $h['song']['artist'] : '',
						'art'      => cb_deck_art_url( isset( $h['song']['art'] ) ? $h['song']['art'] : '' ),
						'playedAt' => isset( $h['played_at'] ) ? (int) $h['played_at'] : 0,
					);
				}
			}
		}

		$out['channels'][] = $item;
	}

	remove_action( 'http_api_curl', 'cb_deck_curl_connect_timeout', 10 );

	update_option( 'cb_deck_state_cache', $out, false );
	set_transient( 'cb_deck_state', $out, DAY_IN_SECONDS );
	cb_deck_write_snapshot( $out );

	delete_transient( 'cb_deck_refresh_lock' );
}
