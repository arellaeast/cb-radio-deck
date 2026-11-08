<?php
/**
 * Plugin Name: Captain Baltimore Radio Deck
 * Description: Redesigned Pirate Radio player for /radio/ — channel cards with live tracks on every channel, progress bar, recently played, sticky mini-bar. Front-end only: reuses the cb-pirate-radio REST backend (now-playing, votes, charts) and merges AzuraCast progress/history server-side.
 * Version: 1.1.14
 * Author: Lisa
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CB_DECK_VERSION', '1.1.14' );

// How old stored state may get before a read asks for a background refresh.
define( 'CB_DECK_STALE_AFTER', 30 );

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
		'pop-rock-and-alternative' => array(
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
		'poll'         => 5000,
		'channels'     => $channels,
		// v1.1.11: the stored state rides along so deck.js can seed its
		// first render from it (see the server-rendered first paint below).
		'initialState' => cb_deck_stored_state(),
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
			'playedAt'     => null,
			'updatedAt'    => null,
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
 * Server-rendered first paint (v1.1.11; charts hydrate since v1.1.12). Until now the page shipped the
 * legacy tuner markup and deck.js built the deck in the browser, swapped
 * it in, and filled the tracks in after its first poll — the visible
 * scramble on every load. Now the deck (and the Top 10) render server-side
 * from the same stored state the polls serve, that state is inlined in
 * the page config for deck.js to seed from, and deck.js binds to the
 * markup already on the page. The ?deck=old escape hatch is untouched:
 * when the deck is disabled, neither this filter nor the enqueue runs.
 * ---------------------------------------------------------------------- */

/** Mirror of deck.js fmtTime(). */
function cb_deck_fmt_time( $sec ) {
	if ( null === $sec || ! is_numeric( $sec ) || $sec < 0 ) {
		return '–:––';
	}
	$sec = (int) floor( (float) $sec );
	$m   = (int) floor( $sec / 60 );
	$s   = $sec % 60;
	return $m . ':' . ( $s < 10 ? '0' : '' ) . $s;
}

/** Mirror of deck.js ago(). */
function cb_deck_ago( $unix ) {
	if ( ! $unix ) {
		return '';
	}
	$mins = max( 1, (int) round( ( time() - (int) $unix ) / 60 ) );
	if ( $mins < 60 ) {
		return $mins . 'm ago';
	}
	$h = (int) floor( $mins / 60 );
	return $h . 'h ' . ( $mins % 60 ) . 'm ago';
}

/** The deck.js transport icons, copied byte-for-byte. */
function cb_deck_icon( $name ) {
	$icons = array(
		'play' => '<svg viewBox="0 0 24 24" width="30" height="30" aria-hidden="true"><path d="M7 4l13 8-13 8z" fill="currentColor"/></svg>',
		'vol' => '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3a3.5 3.5 0 0 0-2-3.15v6.3a3.5 3.5 0 0 0 2-3.15z" fill="currentColor"/></svg>',
		'up' => '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M2 21h3V9H2v12zm19-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L12.17 1 5.59 7.59C5.22 7.95 5 8.45 5 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z" fill="currentColor"/></svg>',
		'down' => '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M22 3h-3v12h3V3zM3 14c0 1.1.9 2 2 2h6.31l-.95 4.57-.03.32c0 .41.17.79.44 1.06L12.83 23l6.58-6.59c.37-.36.59-.86.59-1.41V5c0-1.1-.9-2-2-2H7c-.83 0-1.54.5-1.84 1.22L2.14 11.27c-.09.23-.14.47-.14.73v2z" fill="currentColor"/></svg>',
	);
	return isset( $icons[ $name ] ) ? $icons[ $name ] : '';
}

/**
 * The deck markup: identical in structure to what deck.js builds, with the
 * stored state already filled in for the default (first) channel — the
 * same first render deck.js produces from that state. With no stored
 * state yet, it renders deck.js's own cold placeholders.
 */
function cb_deck_render_deck( $state ) {
	$channels  = cb_deck_channels();
	$by_slug   = array();
	$has_state = false;
	if ( is_array( $state ) && ! empty( $state['channels'] ) ) {
		foreach ( $state['channels'] as $c ) {
			if ( ! empty( $c['slug'] ) ) {
				$by_slug[ $c['slug'] ] = $c;
			}
		}
		$has_state = ! empty( $by_slug );
	}
	$slugs    = array_keys( $channels );
	$cur_slug = $slugs[0];
	$cur      = isset( $by_slug[ $cur_slug ] ) ? $by_slug[ $cur_slug ] : null;
	$song     = ( $cur && isset( $cur['song'] ) && is_array( $cur['song'] ) ) ? $cur['song'] : null;
	$artist   = ( $cur && isset( $cur['artist'] ) && is_array( $cur['artist'] ) ) ? $cur['artist'] : null;

	// Channel cards (deck.js renderCards()).
	$cards = '';
	foreach ( $channels as $slug => $c ) {
		$d     = isset( $by_slug[ $slug ] ) ? $by_slug[ $slug ] : null;
		$track = '—';
		if ( $has_state && $d ) {
			if ( ! empty( $d['song'] ) ) {
				$track = ( isset( $d['song']['title'] ) ? $d['song']['title'] : '—' ) . ( ! empty( $d['song']['artist'] ) ? ' — ' . $d['song']['artist'] : '' );
			} else {
				$track = 'Signal up';
			}
		}
		$on     = ( $slug === $cur_slug );
		$cards .= '<button type="button" class="cbd-card' . ( $on ? ' is-tuned' : '' ) . '" data-slug="' . esc_attr( $slug ) . '" style="--acc:' . esc_attr( $c['accent'] ) . '" aria-pressed="' . ( $on ? 'true' : 'false' ) . '">'
			. '<span class="cbd-card__freq">' . esc_html( $c['freq'] ) . '<small> FM</small></span>'
			. '<span class="cbd-card__name">' . esc_html( $c['label'] ) . '</span>'
			. '<span class="cbd-card__track" data-track>' . esc_html( $track ) . '</span>'
			. '<span class="cbd-eq" aria-hidden="true"><i></i><i></i><i></i><i></i></span>'
			. '</button>';
	}

	// Now-playing panel (deck.js renderNowPlaying()).
	$title       = 'Tuning…';
	$artist_name = '—';
	$artist_url  = '';
	$hood        = '';
	$aboard      = '';
	$live        = 'ON AIR';
	$art         = '';
	if ( $has_state && $cur ) {
		$title       = ( $song && ! empty( $song['title'] ) ) ? $song['title'] : '—';
		$artist_name = ( $artist && ! empty( $artist['name'] ) ) ? $artist['name'] : ( ( $song && ! empty( $song['artist'] ) ) ? $song['artist'] : '—' );
		$artist_url  = ( $artist && ! empty( $artist['url'] ) ) ? $artist['url'] : '';
		$hood        = ( $artist && ! empty( $artist['hood'] ) ) ? 'Reps: ' . $artist['hood'] : '';
		$aboard      = ( isset( $cur['listeners'] ) ? (int) $cur['listeners'] : 0 ) . ' aboard' . ( ! empty( $cur['demo'] ) ? ' · DEMO SIGNAL' : '' );
		$live        = ! empty( $cur['live'] ) ? 'ON AIR' : 'SIGNAL DOWN';
		if ( $song && ! empty( $song['art'] ) ) {
			$art = $song['art'];
		} elseif ( $artist && ! empty( $artist['photo'] ) ) {
			$art = $artist['photo'];
		}
	}

	// Progress (deck.js tickProgress(), on the data's own clock).
	$bar_w        = 0;
	$elapsed_txt  = '0:00';
	$duration_txt = '–:––';
	if ( $cur && isset( $cur['elapsed'] ) && null !== $cur['elapsed'] && ! empty( $cur['duration'] ) ) {
		$anchor = ! empty( $cur['updatedAt'] ) ? (int) $cur['updatedAt'] : ( ( is_array( $state ) && ! empty( $state['generated_at'] ) ) ? (int) $state['generated_at'] : time() );
		$el     = (float) $cur['elapsed'] + ( time() - $anchor );
		if ( $el >= (float) $cur['duration'] ) {
			$bar_w       = 100;
			$elapsed_txt = cb_deck_fmt_time( $cur['duration'] );
		} else {
			$bar_w       = max( 0, min( 100, $el / (float) $cur['duration'] * 100 ) );
			$elapsed_txt = cb_deck_fmt_time( $el );
		}
		$duration_txt = cb_deck_fmt_time( $cur['duration'] );
	}

	// Votes (deck.js paintVotes() before any vote is fetched).
	$votable = ( $cur && ! empty( $cur['submissionId'] ) );
	$hint    = $votable ? 'Rate this track' : 'Voting opens when a real track is on the air';

	// Recently played (deck.js renderNowPlaying()).
	$hist_html = '';
	if ( $has_state && $cur ) {
		if ( ! empty( $cur['history'] ) && is_array( $cur['history'] ) ) {
			foreach ( $cur['history'] as $h ) {
				$hist_html .= '<li class="cbd-history__row">'
					. ( ! empty( $h['art'] ) ? '<img src="' . esc_url( $h['art'] ) . '" alt="" loading="lazy">' : '<span class="cbd-history__noart">♪</span>' )
					. '<span class="cbd-history__info"><strong>' . esc_html( isset( $h['title'] ) ? $h['title'] : '' ) . '</strong><em>' . esc_html( isset( $h['artist'] ) ? $h['artist'] : '' ) . '</em></span>'
					. '<span class="cbd-history__ago">' . esc_html( cb_deck_ago( isset( $h['playedAt'] ) ? $h['playedAt'] : 0 ) ) . '</span></li>';
			}
		} else {
			$hist_html = '<li class="cbd-history__row cbd-history__empty">Nothing logged yet — the night is young.</li>';
		}
	}

	// Dial needle for the tuned channel (deck.js tune()).
	$freq = (float) $channels[ $cur_slug ]['freq'];
	$pct  = min( 100, max( 0, ( $freq - 87.5 ) / ( 93.0 - 87.5 ) * 100 ) );

	$html = '<div class="cbd" id="cb-deck">'
		. '<div class="cbd-mast">'
		. '<span class="cbd-beacon" aria-hidden="true"></span>'
		. '<span class="cbd-callsign">CAPT·BLTMR — PIRATE RADIO</span>'
		. '<span class="cbd-aboard" id="cbd-aboard">' . esc_html( $aboard ) . '</span>'
		. '</div>'
		. '<div class="cbd-cards" role="group" aria-label="Channels">' . $cards . '</div>'
		. '<div class="cbd-scale" aria-hidden="true"><div class="cbd-needle" id="cbd-needle" style="left:' . number_format( $pct, 2, '.', '' ) . '%"></div></div>'
		. '<div class="cbd-main">'
		. '<div class="cbd-artwrap">'
		. '<img class="cbd-art" id="cbd-art" alt=""' . ( '' !== $art ? ' src="' . esc_url( $art ) . '"' : ' hidden' ) . '>'
		. '<div class="cbd-art-fallback" id="cbd-art-fallback"' . ( '' !== $art ? ' hidden' : '' ) . '>🏴‍☠️</div>'
		. '</div>'
		. '<div class="cbd-info">'
		. '<div class="cbd-live-row"><span class="cbd-live" id="cbd-live">' . esc_html( $live ) . '</span><span class="cbd-hood" id="cbd-hood">' . esc_html( $hood ) . '</span></div>'
		. '<h3 class="cbd-title" id="cbd-title">' . esc_html( $title ) . '</h3>'
		. '<a class="cbd-artist" id="cbd-artist" href="' . ( '' !== $artist_url ? esc_url( $artist_url ) : '#' ) . '"' . ( '' !== $artist_url ? '' : ' style="pointer-events:none"' ) . '>' . esc_html( $artist_name ) . '</a>'
		. '<div class="cbd-progress"><div class="cbd-progress__bar" id="cbd-bar" style="width:' . number_format( $bar_w, 2, '.', '' ) . '%"></div></div>'
		. '<div class="cbd-times"><span id="cbd-elapsed">' . esc_html( $elapsed_txt ) . '</span><span id="cbd-duration">' . esc_html( $duration_txt ) . '</span></div>'
		. '<div class="cbd-votes" id="cbd-votes">'
		. '<button type="button" class="cbd-vote" id="cbd-vote-up" aria-pressed="false" aria-label="Vote this track up"' . ( $votable ? '' : ' disabled' ) . '>' . cb_deck_icon( 'up' ) . '</button>'
		. '<button type="button" class="cbd-vote" id="cbd-vote-down" aria-pressed="false" aria-label="Vote this track down"' . ( $votable ? '' : ' disabled' ) . '>' . cb_deck_icon( 'down' ) . '</button>'
		. '<span class="cbd-votes__hint" id="cbd-votes-hint">' . esc_html( $hint ) . '</span>'
		. '</div>'
		. '</div>'
		. '</div>'
		. '<div class="cbd-transport">'
		. '<button type="button" class="cbd-seek" id="cbd-prev" aria-label="Previous channel">◀◀</button>'
		. '<button type="button" class="cbd-play" id="cbd-play" aria-label="Play">' . cb_deck_icon( 'play' ) . '</button>'
		. '<button type="button" class="cbd-seek" id="cbd-next" aria-label="Next channel">▶▶</button>'
		. '<div class="cbd-vol">'
		. '<button type="button" class="cbd-volbtn" id="cbd-mute" aria-label="Mute">' . cb_deck_icon( 'vol' ) . '</button>'
		. '<input type="range" class="cbd-volrange" id="cbd-volume" min="0" max="1" step="0.01" value="0.8" aria-label="Volume">'
		. '</div>'
		. '</div>'
		. '<div class="cbd-history">'
		. '<h4 class="cbd-history__title">Recently played on this channel</h4>'
		. '<ul class="cbd-history__list" id="cbd-history">' . $hist_html . '</ul>'
		. '</div>'
		. '<audio id="cbd-audio" preload="none"></audio>'
		. '</div>';
	return $html;
}

/**
 * The Top 10 block in deck.js's own markup, rows filled from the same
 * charts endpoint deck.js fetches — dispatched in-process, so no loopback
 * request. Returns null when the endpoint has nothing, leaving the legacy
 * markup for deck.js to replace as before.
 */
function cb_deck_render_charts() {
	if ( ! function_exists( 'rest_do_request' ) ) {
		return null;
	}
	$resp = rest_do_request( new WP_REST_Request( 'GET', '/cb-radio/v1/charts/week' ) );
	if ( ! $resp || $resp->is_error() ) {
		return null;
	}
	$data = $resp->get_data();
	if ( ! is_array( $data ) || ! isset( $data['entries'] ) || ! is_array( $data['entries'] ) || empty( $data['entries'] ) ) {
		return null;
	}
	$rows = '';
	foreach ( $data['entries'] as $e ) {
		$name = isset( $e['artist']['name'] ) ? $e['artist']['name'] : '';
		$url  = isset( $e['artist']['url'] ) ? $e['artist']['url'] : '#';
		$rows .= '<li class="cbd-charts__row' . ( ( isset( $e['rank'] ) && $e['rank'] <= 3 ) ? ' is-top' : '' ) . '">'
			. '<span class="cbd-charts__rank">' . ( isset( $e['rank'] ) ? (int) $e['rank'] : '' ) . '</span>'
			. '<span class="cbd-charts__art">' . ( ! empty( $e['art'] ) ? '<img src="' . esc_url( $e['art'] ) . '" alt="" loading="lazy">' : '<span class="cbd-charts__noart">🏴‍☠️</span>' ) . '</span>'
			. '<span class="cbd-charts__info"><span class="cbd-charts__track">' . esc_html( isset( $e['title'] ) ? $e['title'] : '' ) . '</span>'
			. '<a class="cbd-charts__artist" href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>'
			. '<span class="cbd-charts__channel">' . esc_html( isset( $e['channel'] ) ? $e['channel'] : '' ) . '</span></span>'
			. '<span class="cbd-charts__score" title="' . ( isset( $e['up'] ) ? (int) $e['up'] : 0 ) . ' up / ' . ( isset( $e['down'] ) ? (int) $e['down'] : 0 ) . ' down">' . ( ( isset( $e['score'] ) && $e['score'] > 0 ) ? '+' : '' ) . ( isset( $e['score'] ) ? (int) $e['score'] : 0 ) . '</span></li>';
	}
	$demo = ! empty( $data['demo'] );
	// Genre pills (v1.1.13): the chart can narrow to one station. Dock Talk
	// is talk, not a music chart, so it is not offered.
	$genre_labels = array(
		'drill'                    => 'Drill',
		'hip-hop-and-r-and-b'      => 'Hip-Hop & R&B',
		'pop-rock-and-alternative' => 'Rock, Punk & Alt',
		'247-rock-off'             => 'Rock Off',
		'jazz'                     => 'Jazz',
		'country'                  => 'Country',
		'gospel'                   => 'Gospel',
	);
	$pills = '<div class="cbd-charts__genres" role="tablist" aria-label="Chart by genre">'
		. '<button role="tab" data-genre="" class="is-active">All</button>';
	foreach ( $genre_labels as $slug => $label ) {
		$pills .= '<button role="tab" data-genre="' . esc_attr( $slug ) . '">' . esc_html( $label ) . '</button>';
	}
	$pills .= '</div>';
	return '<div class="cb-charts" id="cb-charts" data-period="week" data-press="scale">'
		. '<div class="cbd-charts__head">'
		. '<h2 class="cbd-charts__title">🏴‍☠️ The Captain’s Top 10</h2>'
		. '<div class="cbd-charts__toggle" role="tablist" aria-label="Chart period">'
		. '<button role="tab" data-period="week" class="is-active">This Week</button>'
		. '<button role="tab" data-period="month">This Month</button>'
		. '</div></div>'
		. $pills
		. '<p class="cbd-charts__demo" id="cbd-charts-demo"' . ( $demo ? '' : ' hidden' ) . '>DEMO SIGNAL — sample chart until real votes land</p>'
		. '<ol class="cbd-charts__list" id="cbd-charts-list">' . $rows . '</ol>'
		. '</div>';
}

/** Replace the balanced <div> block containing $marker with $replacement. */
function cb_deck_replace_block( $content, $marker, $replacement ) {
	$at = strpos( $content, $marker );
	if ( false === $at ) {
		return $content;
	}
	$head      = substr( $content, 0, $at );
	$div_start = strrpos( $head, '<div' );
	if ( false === $div_start ) {
		return $content;
	}
	$len   = strlen( $content );
	$depth = 0;
	$pos   = $div_start;
	$end   = null;
	while ( $pos < $len ) {
		$open  = strpos( $content, '<div', $pos );
		$close = strpos( $content, '</div>', $pos );
		if ( false === $close ) {
			break;
		}
		if ( false !== $open && $open < $close ) {
			$depth++;
			$pos = $open + 4;
		} else {
			$depth--;
			$pos = $close + 6;
			if ( 0 === $depth ) {
				$end = $pos;
				break;
			}
		}
	}
	if ( null === $end ) {
		return $content;
	}
	return substr( $content, 0, $div_start ) . $replacement . substr( $content, $end );
}

add_filter( 'the_content', 'cb_deck_ssr_content', 25 );
function cb_deck_ssr_content( $content ) {
	if ( ! is_page( 'radio' ) || ! cb_deck_enabled() || ! in_the_loop() ) {
		return $content;
	}
	if ( false === strpos( $content, 'id="cb-tuner"' ) ) {
		return $content;
	}
	$content = cb_deck_replace_block( $content, 'id="cb-tuner"', cb_deck_render_deck( cb_deck_stored_state() ) );
	if ( false !== strpos( $content, 'id="cb-charts"' ) ) {
		$charts = cb_deck_render_charts();
		if ( null !== $charts ) {
			$content = cb_deck_replace_block( $content, 'id="cb-charts"', $charts );
		}
	}
	return $content;
}

/* -------------------------------------------------------------------------
 * Background refresh. A system cron runner on the box calls this every
 * 15 seconds (WP-Cron's own minute schedule remains as a backstop). This
 * is the only place the deck talks to AzuraCast or to the cb-radio
 * now-playing proxy, and every call carries a short timeout so a hung
 * radio can never hold it for long either.
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
	// Short lock: the runner calls every 15 seconds, so a run that dies
	// mid-flight must stop blocking the cadence within a couple of cycles.
	set_transient( 'cb_deck_refresh_lock', 1, 30 );

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

	// One aggregate AzuraCast pull covers every station. The per-station
	// calls each cost about half a second of AzuraCast generation time,
	// which made a full rebuild take most of ten seconds; the aggregate
	// returns all eight stations in a single response of the same shape.
	// Any station the aggregate misses falls back to its own call below.
	$az_base    = cb_deck_azuracast_base();
	$stations   = array();
	$station_at = array();
	foreach ( $channels as $c ) {
		$stations[ $c['station'] ]   = null;
		$station_at[ $c['station'] ] = 0;
	}
	$res = wp_remote_get( rtrim( $az_base, '/' ), array( 'timeout' => 4 ) );
	if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
		$all = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( is_array( $all ) ) {
			$got_at = time();
			foreach ( $all as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$sc = isset( $entry['station']['shortcode'] ) ? $entry['station']['shortcode'] : '';
				if ( '' !== $sc && array_key_exists( $sc, $stations ) ) {
					$stations[ $sc ]   = $entry;
					$station_at[ $sc ] = $got_at;
				}
			}
		}
	}
	foreach ( $stations as $st => $data ) {
		if ( null !== $data ) {
			continue;
		}
		$res = wp_remote_get( $az_base . $st, array( 'timeout' => 4 ) );
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$stations[ $st ]   = json_decode( wp_remote_retrieve_body( $res ), true );
			$station_at[ $st ] = time();
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
			'playedAt'     => null,
			'updatedAt'    => null,
			'history'      => array(),
			'demo'         => false,
		);

		$np_ok = false;
		$np    = null;
		// Ask the cb-radio proxy in-process. An HTTP loopback costs a full
		// WordPress boot per channel (measured at about 0.9s each on this
		// box), which by itself pushed a refresh cycle toward 15 seconds;
		// internal REST dispatch returns the same payload in milliseconds.
		// The HTTP loopback stays as a fallback if internal dispatch fails.
		if ( function_exists( 'rest_do_request' ) ) {
			$resp = rest_do_request( new WP_REST_Request( 'GET', '/cb-radio/v1/now-playing/' . $slug ) );
			if ( $resp && ! $resp->is_error() && 200 === (int) $resp->get_status() ) {
				$data = $resp->get_data();
				if ( is_array( $data ) ) {
					$np = $data;
				}
			}
		}
		if ( ! is_array( $np ) ) {
			$res = wp_remote_get( rest_url( 'cb-radio/v1/now-playing/' . $slug ), array( 'timeout' => 4 ) );
			if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
				$np = json_decode( wp_remote_retrieve_body( $res ), true );
			}
		}
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
					if ( isset( $az['now_playing']['played_at'] ) ) {
						$item['playedAt'] = (int) $az['now_playing']['played_at'];
					}
					// The instant this timing was true, so the browser can
					// extrapolate progress from the data's own clock rather
					// than from whenever it happened to poll.
					$item['updatedAt'] = ! empty( $station_at[ $c['station'] ] ) ? $station_at[ $c['station'] ] : time();
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
			$npz        = $az['now_playing'];
			$az_title   = isset( $npz['song']['title'] ) ? $npz['song']['title'] : '';
			$item_title = isset( $item['song']['title'] ) ? $item['song']['title'] : '';
			if ( '' !== $az_title && $az_title === $item_title ) {
				// Timing is adopted only for the song actually on screen, so
				// the progress bar never runs on the next track's clock
				// while the vote button still points at the displayed one.
				if ( isset( $npz['elapsed'] ) ) {
					$item['elapsed'] = (int) $npz['elapsed'];
				}
				if ( isset( $npz['duration'] ) ) {
					$item['duration'] = (int) $npz['duration'];
				}
				if ( isset( $npz['played_at'] ) ) {
					$item['playedAt'] = (int) $npz['played_at'];
				}
				$item['updatedAt'] = ! empty( $station_at[ $c['station'] ] ) ? $station_at[ $c['station'] ] : time();
			} elseif ( is_array( $prev_item ) && '' !== $item_title
				&& isset( $prev_item['song']['title'] ) && $prev_item['song']['title'] === $item_title ) {
				// AzuraCast has rolled to the next song but the proxy, and
				// with it the vote context, still shows this one: carry the
				// previous timing so the bar keeps tracking the displayed
				// track instead of freezing or jumping.
				$item['elapsed']   = isset( $prev_item['elapsed'] ) ? $prev_item['elapsed'] : null;
				$item['duration']  = isset( $prev_item['duration'] ) ? $prev_item['duration'] : null;
				$item['playedAt']  = isset( $prev_item['playedAt'] ) ? $prev_item['playedAt'] : null;
				$item['updatedAt'] = isset( $prev_item['updatedAt'] ) ? $prev_item['updatedAt'] : null;
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

		// No timing could be adopted (AzuraCast unreachable this cycle):
		// carry the previous timing when it belongs to the displayed song.
		if ( null === $item['elapsed'] && is_array( $prev_item )
			&& ! empty( $item['song']['title'] )
			&& isset( $prev_item['song']['title'] ) && $prev_item['song']['title'] === $item['song']['title'] ) {
			$item['elapsed']   = isset( $prev_item['elapsed'] ) ? $prev_item['elapsed'] : null;
			$item['duration']  = isset( $prev_item['duration'] ) ? $prev_item['duration'] : null;
			$item['playedAt']  = isset( $prev_item['playedAt'] ) ? $prev_item['playedAt'] : null;
			$item['updatedAt'] = isset( $prev_item['updatedAt'] ) ? $prev_item['updatedAt'] : null;
		}

		$out['channels'][] = $item;
	}

	remove_action( 'http_api_curl', 'cb_deck_curl_connect_timeout', 10 );

	update_option( 'cb_deck_state_cache', $out, false );
	set_transient( 'cb_deck_state', $out, DAY_IN_SECONDS );
	cb_deck_write_snapshot( $out );

	delete_transient( 'cb_deck_refresh_lock' );
}
