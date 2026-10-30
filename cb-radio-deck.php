<?php
/**
 * Plugin Name: Captain Baltimore Radio Deck
 * Description: Redesigned Pirate Radio player for /radio/ — channel cards with live tracks on every channel, progress bar, recently played, sticky mini-bar. Front-end only: reuses the cb-pirate-radio REST backend (now-playing, votes, charts) and merges AzuraCast progress/history server-side.
 * Version: 1.1.4
 * Author: Lisa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CB_DECK_VERSION', '1.1.4' );

/**
 * Channel map: slug => label / freq / AzuraCast station shortcode / accent.
 * Streams come from the cb-radio now-playing endpoint at runtime.
 */
function cb_deck_channels() {
	return array(
		'drill'              => array(
			'label'   => 'Drill',
			'freq'    => '87.90',
			'station' => 'local_baltimore_hip_hop_and_rb',
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
			'station' => 'captainbaltimore',
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

	$config = array(
		'stateUrl'  => rest_url( 'cb-deck/v1/state' ),
		'chartBase' => rest_url( 'cb-radio/v1/charts/' ),
		'voteUrl'   => rest_url( 'cb-radio/v1/vote' ),
		'myVoteUrl' => rest_url( 'cb-radio/v1/my-vote/' ),
		'nonce'     => wp_create_nonce( 'wp_rest' ),
		'poll'      => 15000,
		'channels'  => $channels,
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

/**
 * Merged per-channel state: the cb-radio now-playing proxy (authoritative
 * song / submission / artist data) + AzuraCast progress and song history.
 * Cached 10s so a busy page never hammers either upstream.
 */
function cb_deck_state() {
	$cached = get_transient( 'cb_deck_state' );
	if ( false !== $cached ) {
		return rest_ensure_response( $cached );
	}

	$channels = cb_deck_channels();
	$out      = array( 'channels' => array() );

	// One AzuraCast pull per unique station.
	$stations = array();
	foreach ( $channels as $c ) {
		$st = $c['station'];
		if ( isset( $stations[ $st ] ) ) {
			continue;
		}
		$stations[ $st ] = null;
		$res = wp_remote_get( 'https://radio.captainbaltimore.com/api/nowplaying/' . $st, array( 'timeout' => 6 ) );
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$stations[ $st ] = json_decode( wp_remote_retrieve_body( $res ), true );
		}
	}

	foreach ( $channels as $slug => $c ) {
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

		$res = wp_remote_get( rest_url( 'cb-radio/v1/now-playing/' . $slug ), array( 'timeout' => 6 ) );
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$np = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( is_array( $np ) ) {
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

	set_transient( 'cb_deck_state', $out, 10 );
	return rest_ensure_response( $out );
}
