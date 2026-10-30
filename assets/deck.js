/* Captain Baltimore Radio Deck — front-end.
 * Replaces the legacy tuner UI on /radio/. Same backend contract as the old
 * tuner (cb-radio REST: votes, charts), plus the cb-deck merged state feed
 * for progress + history. One audio element, one poll, all channels visible.
 */
(function () {
	'use strict';

	if (typeof window.CB_DECK === 'undefined') return;
	var cfg = window.CB_DECK;

	var oldTuner = document.getElementById('cb-tuner');
	if (!oldTuner) return;

	/* ---------- helpers ---------- */
	function esc(s) {
		var d = document.createElement('span');
		d.textContent = s == null ? '' : String(s);
		return d.innerHTML;
	}
	function fmtTime(sec) {
		if (sec == null || isNaN(sec) || sec < 0) return '–:––';
		sec = Math.floor(sec);
		var m = Math.floor(sec / 60), s = sec % 60;
		return m + ':' + (s < 10 ? '0' : '') + s;
	}
	function ago(unix) {
		if (!unix) return '';
		var mins = Math.max(1, Math.round((Date.now() / 1000 - unix) / 60));
		if (mins < 60) return mins + 'm ago';
		var h = Math.floor(mins / 60);
		return h + 'h ' + (mins % 60) + 'm ago';
	}
	function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
	function lsSet(k, v) { try { if (v == null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch (e) {} }

	var ICON = {
		play: '<svg viewBox="0 0 24 24" width="30" height="30" aria-hidden="true"><path d="M7 4l13 8-13 8z" fill="currentColor"/></svg>',
		pause: '<svg viewBox="0 0 24 24" width="30" height="30" aria-hidden="true"><path d="M6 4h4v16H6zM14 4h4v16h-4z" fill="currentColor"/></svg>',
		vol: '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3a3.5 3.5 0 0 0-2-3.15v6.3a3.5 3.5 0 0 0 2-3.15z" fill="currentColor"/></svg>',
		mute: '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M3 9v6h4l5 5V4L7 9H3zm14.6 3l2.2-2.2-1.4-1.4-2.2 2.2-2.2-2.2-1.4 1.4L14.8 12l-2.2 2.2 1.4 1.4 2.2-2.2 2.2 2.2 1.4-1.4L17.6 12z" fill="currentColor"/></svg>',
		up: '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M2 21h3V9H2v12zm19-11c0-1.1-.9-2-2-2h-6.31l.95-4.57.03-.32c0-.41-.17-.79-.44-1.06L12.17 1 5.59 7.59C5.22 7.95 5 8.45 5 9v10c0 1.1.9 2 2 2h9c.83 0 1.54-.5 1.84-1.22l3.02-7.05c.09-.23.14-.47.14-.73v-2z" fill="currentColor"/></svg>',
		down: '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M22 3h-3v12h3V3zM3 14c0 1.1.9 2 2 2h6.31l-.95 4.57-.03.32c0 .41.17.79.44 1.06L12.83 23l6.58-6.59c.37-.36.59-.86.59-1.41V5c0-1.1-.9-2-2-2H7c-.83 0-1.54.5-1.84 1.22L2.14 11.27c-.09.23-.14.47-.14.73v2z" fill="currentColor"/></svg>'
	};

	/* ---------- state ---------- */
	var channels = cfg.channels || [];
	var state = {
		data: {},            // slug -> merged channel state
		slug: lsGet('cbdChannel') || (channels[0] && channels[0].slug),
		playing: false,
		loading: false,
		myVote: 0,
		submissionId: 0,
		trackKey: '',
		fetchedAt: 0,
		progressTimer: null,
		pollTimer: null,
		refetchQueued: false
	};
	if (!channels.some(function (c) { return c.slug === state.slug; })) {
		state.slug = channels[0] && channels[0].slug;
	}

	/* ---------- build the deck ---------- */
	var deck = document.createElement('div');
	deck.className = 'cbd';
	deck.id = 'cb-deck';
	deck.innerHTML =
		'<div class="cbd-mast">' +
			'<span class="cbd-beacon" aria-hidden="true"></span>' +
			'<span class="cbd-callsign">CAPT·BLTMR — PIRATE RADIO</span>' +
			'<span class="cbd-aboard" id="cbd-aboard"></span>' +
		'</div>' +
		'<div class="cbd-cards" role="group" aria-label="Channels">' +
			channels.map(function (c) {
				return '<button type="button" class="cbd-card" data-slug="' + esc(c.slug) + '" style="--acc:' + esc(c.accent) + '" aria-pressed="false">' +
					'<span class="cbd-card__freq">' + esc(c.freq) + '<small> FM</small></span>' +
					'<span class="cbd-card__name">' + esc(c.label) + '</span>' +
					'<span class="cbd-card__track" data-track>—</span>' +
					'<span class="cbd-eq" aria-hidden="true"><i></i><i></i><i></i><i></i></span>' +
				'</button>';
			}).join('') +
		'</div>' +
		'<div class="cbd-scale" aria-hidden="true"><div class="cbd-needle" id="cbd-needle"></div></div>' +
		'<div class="cbd-main">' +
			'<div class="cbd-artwrap">' +
				'<img class="cbd-art" id="cbd-art" alt="" hidden>' +
				'<div class="cbd-art-fallback" id="cbd-art-fallback">🏴‍☠️</div>' +
			'</div>' +
			'<div class="cbd-info">' +
				'<div class="cbd-live-row"><span class="cbd-live" id="cbd-live">ON AIR</span><span class="cbd-hood" id="cbd-hood"></span></div>' +
				'<h3 class="cbd-title" id="cbd-title">Tuning…</h3>' +
				'<a class="cbd-artist" id="cbd-artist" href="#">—</a>' +
				'<div class="cbd-progress"><div class="cbd-progress__bar" id="cbd-bar"></div></div>' +
				'<div class="cbd-times"><span id="cbd-elapsed">0:00</span><span id="cbd-duration">–:––</span></div>' +
				'<div class="cbd-votes" id="cbd-votes">' +
					'<button type="button" class="cbd-vote" id="cbd-vote-up" aria-pressed="false" aria-label="Vote this track up">' + ICON.up + '</button>' +
					'<button type="button" class="cbd-vote" id="cbd-vote-down" aria-pressed="false" aria-label="Vote this track down">' + ICON.down + '</button>' +
					'<span class="cbd-votes__hint" id="cbd-votes-hint"></span>' +
				'</div>' +
			'</div>' +
		'</div>' +
		'<div class="cbd-transport">' +
			'<button type="button" class="cbd-seek" id="cbd-prev" aria-label="Previous channel">◀◀</button>' +
			'<button type="button" class="cbd-play" id="cbd-play" aria-label="Play">' + ICON.play + '</button>' +
			'<button type="button" class="cbd-seek" id="cbd-next" aria-label="Next channel">▶▶</button>' +
			'<div class="cbd-vol">' +
				'<button type="button" class="cbd-volbtn" id="cbd-mute" aria-label="Mute">' + ICON.vol + '</button>' +
				'<input type="range" class="cbd-volrange" id="cbd-volume" min="0" max="1" step="0.01" value="0.8" aria-label="Volume">' +
			'</div>' +
		'</div>' +
		'<div class="cbd-history">' +
			'<h4 class="cbd-history__title">Recently played on this channel</h4>' +
			'<ul class="cbd-history__list" id="cbd-history"></ul>' +
		'</div>' +
		'<audio id="cbd-audio" preload="none"></audio>';

	oldTuner.insertAdjacentElement('afterend', deck);
	oldTuner.remove();

	var $ = function (id) { return document.getElementById(id); };
	var audio = $('cbd-audio'), playBtn = $('cbd-play'), needle = $('cbd-needle');
	var cardEls = {};
	deck.querySelectorAll('.cbd-card').forEach(function (el) { cardEls[el.dataset.slug] = el; });

	/* ---------- sticky mini-bar ---------- */
	var mini = document.createElement('div');
	mini.className = 'cbd-mini';
	mini.hidden = true;
	mini.innerHTML =
		'<button type="button" class="cbd-mini__play" id="cbd-mini-play" aria-label="Play or pause">' + ICON.play + '</button>' +
		'<button type="button" class="cbd-mini__info" id="cbd-mini-info">' +
			'<span class="cbd-mini__track" id="cbd-mini-track">—</span>' +
			'<span class="cbd-mini__chan" id="cbd-mini-chan"></span>' +
		'</button>';
	document.body.appendChild(mini);
	var miniPlay = $('cbd-mini-play');
	if ('IntersectionObserver' in window) {
		new IntersectionObserver(function (entries) {
			mini.hidden = entries[0].isIntersecting || !state.trackKey;
		}, { threshold: 0.05 }).observe(deck);
	}
	$('cbd-mini-info').addEventListener('click', function () {
		deck.scrollIntoView({ behavior: 'smooth', block: 'center' });
	});

	/* ---------- audio + transport ---------- */
	var savedVol = parseFloat(lsGet('cbdVolume'));
	if (!isNaN(savedVol)) audio.volume = savedVol; else audio.volume = 0.8;
	$('cbd-volume').value = audio.volume;
	if (lsGet('cbdMuted') === '1') { audio.muted = true; paintMute(); }

	function channelIndex(slug) {
		for (var i = 0; i < channels.length; i++) if (channels[i].slug === slug) return i;
		return 0;
	}
	function currentData() { return state.data[state.slug] || null; }

	function setPlayingUI() {
		playBtn.innerHTML = state.playing ? ICON.pause : ICON.play;
		playBtn.setAttribute('aria-label', state.playing ? 'Pause' : 'Play');
		playBtn.classList.toggle('is-playing', state.playing);
		miniPlay.innerHTML = state.playing ? ICON.pause : ICON.play;
		deck.classList.toggle('is-playing', state.playing);
		document.querySelectorAll('.cbd-card').forEach(function (el) {
			el.classList.toggle('is-sounding', state.playing && el.dataset.slug === state.slug);
		});
	}

	function loadAndPlay() {
		var d = currentData();
		if (!d || !d.stream) return;
		state.loading = true;
		if (audio.dataset.stream !== d.stream) {
			audio.src = d.stream;
			audio.dataset.stream = d.stream;
		}
		audio.play().then(function () {
			state.playing = true; state.loading = false; setPlayingUI();
		}).catch(function () {
			state.playing = false; state.loading = false; setPlayingUI();
		});
	}
	function stopPlayback() {
		audio.pause();
		audio.removeAttribute('src');
		audio.dataset.stream = '';
		audio.load();
		state.playing = false;
		setPlayingUI();
	}
	function togglePlay() { state.playing ? stopPlayback() : loadAndPlay(); }
	playBtn.addEventListener('click', togglePlay);
	miniPlay.addEventListener('click', togglePlay);
	audio.addEventListener('error', function () { if (state.playing) { state.playing = false; setPlayingUI(); } });

	function tune(slug, keepPlaying) {
		if (!cardEls[slug]) return;
		state.slug = slug;
		lsSet('cbdChannel', slug);
		deck.querySelectorAll('.cbd-card').forEach(function (el) {
			var on = el.dataset.slug === slug;
			el.classList.toggle('is-tuned', on);
			el.setAttribute('aria-pressed', on ? 'true' : 'false');
		});
		var freq = parseFloat((channels[channelIndex(slug)] || {}).freq || '87.9');
		var pct = Math.min(100, Math.max(0, (freq - 87.5) / (93.0 - 87.5) * 100));
		needle.style.left = pct + '%';
		renderNowPlaying();
		if (keepPlaying) loadAndPlay();
	}

	deck.querySelectorAll('.cbd-card').forEach(function (el) {
		el.addEventListener('click', function () {
			if (el.dataset.slug !== state.slug) tune(el.dataset.slug, state.playing);
		});
	});
	$('cbd-prev').addEventListener('click', function () {
		tune(channels[(channelIndex(state.slug) + channels.length - 1) % channels.length].slug, state.playing);
	});
	$('cbd-next').addEventListener('click', function () {
		tune(channels[(channelIndex(state.slug) + 1) % channels.length].slug, state.playing);
	});

	$('cbd-volume').addEventListener('input', function () {
		audio.volume = parseFloat(this.value);
		audio.muted = false;
		lsSet('cbdVolume', audio.volume);
		lsSet('cbdMuted', null);
		paintMute();
	});
	function paintMute() {
		$('cbd-mute').innerHTML = audio.muted || audio.volume === 0 ? ICON.mute : ICON.vol;
		$('cbd-mute').setAttribute('aria-label', audio.muted ? 'Unmute' : 'Mute');
	}
	$('cbd-mute').addEventListener('click', function () {
		audio.muted = !audio.muted;
		lsSet('cbdMuted', audio.muted ? '1' : null);
		paintMute();
	});

	/* ---------- votes ---------- */
	function paintVotes() {
		$('cbd-vote-up').classList.toggle('is-mine', state.myVote === 1);
		$('cbd-vote-down').classList.toggle('is-mine', state.myVote === -1);
		$('cbd-vote-up').setAttribute('aria-pressed', state.myVote === 1 ? 'true' : 'false');
		$('cbd-vote-down').setAttribute('aria-pressed', state.myVote === -1 ? 'true' : 'false');
		var votable = state.submissionId > 0;
		$('cbd-vote-up').disabled = !votable;
		$('cbd-vote-down').disabled = !votable;
		$('cbd-votes-hint').textContent = votable ? 'Rate this track' : 'Voting opens when a real track is on the air';
	}
	function fetchMyVote(id) {
		fetch(cfg.myVoteUrl + id).then(function (r) { return r.json(); }).then(function (res) {
			if (state.submissionId !== id) return;
			state.myVote = (res && res.vote) || 0;
			paintVotes();
		}).catch(function () {});
	}
	function castVote(v) {
		if (!state.submissionId) return;
		var id = state.submissionId;
		fetch(cfg.voteUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify({ submission_id: id, vote: v })
		}).then(function (r) { return r.json(); }).then(function (res) {
			if (!res || !res.ok || state.submissionId !== id) return;
			state.myVote = v;
			paintVotes();
		}).catch(function () {});
	}
	$('cbd-vote-up').addEventListener('click', function () { castVote(1); });
	$('cbd-vote-down').addEventListener('click', function () { castVote(-1); });

	/* ---------- rendering ---------- */
	function renderCards() {
		channels.forEach(function (c) {
			var d = state.data[c.slug], el = cardEls[c.slug];
			if (!el) return;
			var t = el.querySelector('[data-track]');
			if (d && d.song) t.textContent = (d.song.title || '—') + (d.song.artist ? ' — ' + d.song.artist : '');
			else if (d) t.textContent = 'Signal up';
		});
	}

	function renderNowPlaying() {
		var d = currentData();
		if (!d) return;

		$('cbd-title').textContent = (d.song && d.song.title) || '—';
		var artistEl = $('cbd-artist');
		var artistName = (d.artist && d.artist.name) || (d.song && d.song.artist) || '—';
		artistEl.textContent = artistName;
		artistEl.href = (d.artist && d.artist.url) || '#';
		artistEl.style.pointerEvents = (d.artist && d.artist.url) ? '' : 'none';
		$('cbd-hood').textContent = (d.artist && d.artist.hood) ? 'Reps: ' + d.artist.hood : '';
		$('cbd-aboard').textContent = (typeof d.listeners === 'number' ? d.listeners + ' aboard' : '') + (d.demo ? ' · DEMO SIGNAL' : '');
		$('cbd-live').textContent = d.live ? 'ON AIR' : 'SIGNAL DOWN';

		var art = (d.song && d.song.art) || (d.artist && d.artist.photo) || '';
		if (art) { $('cbd-art').src = art; $('cbd-art').hidden = false; $('cbd-art-fallback').hidden = true; }
		else { $('cbd-art').hidden = true; $('cbd-art-fallback').hidden = false; }

		$('cbd-mini-track').textContent = ((d.song && d.song.title) || '—') + ' — ' + artistName;
		var ch = channels[channelIndex(state.slug)] || {};
		$('cbd-mini-chan').textContent = (ch.freq || '') + ' FM · ' + (ch.label || '');

		// Vote context follows the track.
		var key = (d.submissionId || 0) + '|' + ((d.song && d.song.title) || '');
		if (key !== state.trackKey) {
			state.trackKey = key;
			state.submissionId = d.submissionId || 0;
			state.myVote = 0;
			paintVotes();
			if (state.submissionId) fetchMyVote(state.submissionId);
		}

		// Recently played.
		var hist = d.history || [];
		$('cbd-history').innerHTML = hist.length ? hist.map(function (h) {
			return '<li class="cbd-history__row">' +
				(h.art ? '<img src="' + esc(h.art) + '" alt="" loading="lazy">' : '<span class="cbd-history__noart">♪</span>') +
				'<span class="cbd-history__info"><strong>' + esc(h.title) + '</strong><em>' + esc(h.artist) + '</em></span>' +
				'<span class="cbd-history__ago">' + esc(ago(h.playedAt)) + '</span></li>';
		}).join('') : '<li class="cbd-history__row cbd-history__empty">Nothing logged yet — the night is young.</li>';

		renderCards();
		tickProgress();
	}

	function tickProgress() {
		var d = currentData();
		if (!d || d.elapsed == null || !d.duration) {
			$('cbd-bar').style.width = '0%';
			$('cbd-elapsed').textContent = '0:00';
			$('cbd-duration').textContent = '–:––';
			return;
		}
		var elapsed = d.elapsed + (Date.now() - state.fetchedAt) / 1000;
		if (elapsed >= d.duration) {
			$('cbd-bar').style.width = '100%';
			if (!state.refetchQueued) {
				state.refetchQueued = true;
				setTimeout(function () { state.refetchQueued = false; fetchState(); }, 1500);
			}
			return;
		}
		$('cbd-bar').style.width = Math.min(100, elapsed / d.duration * 100) + '%';
		$('cbd-elapsed').textContent = fmtTime(elapsed);
		$('cbd-duration').textContent = fmtTime(d.duration);
	}

	function fetchState() {
		fetch(cfg.stateUrl).then(function (r) { return r.json(); }).then(function (res) {
			if (!res || !res.channels) return;
			res.channels.forEach(function (c) { state.data[c.slug] = c; });
			state.fetchedAt = Date.now();
			renderNowPlaying();
		}).catch(function () { /* keep last known state */ });
	}

	/* ---------- charts ---------- */
	var chartsRoot = document.getElementById('cb-charts');
	if (chartsRoot && cfg.chartBase) {
		chartsRoot.innerHTML =
			'<div class="cbd-charts__head">' +
				'<h2 class="cbd-charts__title">🏴‍☠️ The Captain’s Top 10</h2>' +
				'<div class="cbd-charts__toggle" role="tablist" aria-label="Chart period">' +
					'<button role="tab" data-period="week" class="is-active">This Week</button>' +
					'<button role="tab" data-period="month">This Month</button>' +
				'</div>' +
			'</div>' +
			'<p class="cbd-charts__demo" id="cbd-charts-demo" hidden>DEMO SIGNAL — sample chart until real votes land</p>' +
			'<ol class="cbd-charts__list" id="cbd-charts-list"><li class="cbd-charts__loading">Tuning…</li></ol>';
		chartsRoot.removeAttribute('data-press');

		var list = chartsRoot.querySelector('#cbd-charts-list');
		var demoFlag = chartsRoot.querySelector('#cbd-charts-demo');
		var tabs = chartsRoot.querySelectorAll('[data-period]');

		function loadCharts(period) {
			tabs.forEach(function (t) { t.classList.toggle('is-active', t.dataset.period === period); });
			fetch(cfg.chartBase + period).then(function (r) { return r.json(); }).then(function (data) {
				if (!data || !data.entries) return;
				demoFlag.hidden = !data.demo;
				list.innerHTML = data.entries.map(function (e) {
					var name = e.artist ? e.artist.name : '';
					var url = e.artist ? e.artist.url : '#';
					return '<li class="cbd-charts__row' + (e.rank <= 3 ? ' is-top' : '') + '">' +
						'<span class="cbd-charts__rank">' + e.rank + '</span>' +
						'<span class="cbd-charts__art">' + (e.art ? '<img src="' + esc(e.art) + '" alt="" loading="lazy">' : '<span class="cbd-charts__noart">🏴‍☠️</span>') + '</span>' +
						'<span class="cbd-charts__info"><span class="cbd-charts__track">' + esc(e.title) + '</span>' +
						'<a class="cbd-charts__artist" href="' + esc(url) + '">' + esc(name) + '</a>' +
						'<span class="cbd-charts__channel">' + esc(e.channel) + '</span></span>' +
						'<span class="cbd-charts__score" title="' + e.up + ' up / ' + e.down + ' down">' + (e.score > 0 ? '+' : '') + e.score + '</span></li>';
				}).join('');
			}).catch(function () {
				list.innerHTML = '<li class="cbd-charts__loading">Signal lost — try again.</li>';
			});
		}
		tabs.forEach(function (t) { t.addEventListener('click', function () { loadCharts(t.dataset.period); }); });
		loadCharts('week');
	}

	/* ---------- boot ---------- */
	tune(state.slug, false);
	setPlayingUI();
	paintVotes();
	fetchState();
	state.pollTimer = setInterval(function () { if (!document.hidden) fetchState(); }, cfg.poll || 15000);
	state.progressTimer = setInterval(tickProgress, 1000);
})();
