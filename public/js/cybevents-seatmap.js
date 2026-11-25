/**
 * WM Cyber Events Core — seat map, browser side.
 *
 * Keeps the drawing current via ETag-cached snapshot polling (with Page Visibility API pause) by
 * default, or via Server-Sent Events when transport is 'sse' (falling back to polling where
 * EventSource is missing). It repaints the cells, the numbers and the table from what the server
 * sends; it never decides occupancy itself.
 *
 * Clicking a session row books or releases a seat through POST /book-sessions — the same route the
 * session selector uses, so the conditional UPDATE on the database still settles who gets the last
 * seat. Without a participant token in the page the map is read-only.
 */

(function () {
	'use strict';

	function boot() {
		var maps = document.querySelectorAll('.cybevents-seatmap');
		for (var i = 0; i < maps.length; i++) {
			start(maps[i]);
		}
	}

	/** The participant token of the current page, from its query string. */
	function tokenFromUrl() {
		try {
			return new URLSearchParams(window.location.search).get('token') || '';
		} catch (e) {
			return '';
		}
	}

	function start(root) {
		var eventId = parseInt(root.dataset.eventId, 10);
		var stream = root.dataset.stream;
		var snapshot = root.dataset.snapshot || '';
		var transport = root.dataset.transport || 'etag-poll';
		var live = '1' === root.dataset.live;
		// The token is read from the URL, never from the markup: a copy in an attribute would sit in
		// whatever cache holds the page.
		var token = '1' === root.dataset.canBook ? tokenFromUrl() : '';
		var status = root.querySelector('.cybevents-seatmap-status');

		if (!eventId || (!snapshot && !stream)) {
			return;
		}

		if (status) {
			status.textContent = live ? (status.dataset.idle || '') : (status.dataset.static || '');
		}

		if (token) {
			wireBooking(root, eventId, token);
		}

		if (!live) {
			return;
		}

		if ('sse' !== transport && snapshot) {
			pollSnapshot(root, snapshot + (snapshot.indexOf('?') === -1 ? '?' : '&') + 'event_id=' + eventId);
			return;
		}

		if (!stream) {
			return;
		}

		var url = stream + (stream.indexOf('?') === -1 ? '?' : '&') + 'event_id=' + eventId;

		if (window.EventSource) {
			listen(root, url);
		} else {
			// No EventSource: the same route answers with one snapshot per connection anyway, so a
			// slow poll keeps the map roughly current instead of leaving it frozen.
			window.setInterval(function () {
				fetch(url, { headers: { Accept: 'text/event-stream' } })
					.then(function (response) { return response.text(); })
					.then(function (text) {
						var payload = lastDataLine(text);
						if (payload) {
							apply(root, payload);
						}
					})
					.catch(function () { /* the next tick tries again */ });
			}, 15000);
		}
	}

	/** Poll /seat-snapshot with ETag (If-None-Match) and pause when the page is hidden. */
	function pollSnapshot(root, url) {
		var timer = null;
		var inFlight = false;

		function pollOnce() {
			if (document.visibilityState === 'hidden' || inFlight) {
				return;
			}
			inFlight = true;

			var headers = { Accept: 'application/json' };
			if (root.dataset.digest) {
				headers['If-None-Match'] = '"' + root.dataset.digest + '"';
			}

			fetch(url, { headers: headers })
				.then(function (response) {
					if (304 === response.status) {
						return null;
					}
					if (200 !== response.status) {
						return null;
					}
					return response.json();
				})
				.then(function (json) {
					inFlight = false;
					if (json) {
						apply(root, json);
					}
				})
				.catch(function () {
					inFlight = false;
					/* the next tick tries again */
				});
		}

		function startTimer() {
			if (!timer) {
				timer = window.setInterval(pollOnce, 3000);
			}
		}

		function stopTimer() {
			if (timer) {
				window.clearInterval(timer);
				timer = null;
			}
		}

		document.addEventListener('visibilitychange', function () {
			if (document.visibilityState === 'hidden') {
				stopTimer();
			} else if (document.visibilityState === 'visible') {
				pollOnce();
				startTimer();
			} else {
				startTimer();
			}
		});

		if (document.visibilityState !== 'hidden') {
			startTimer();
		}
	}

	/** The payload of the last "data:" line of a stream body. */
	function lastDataLine(text) {
		var lines = String(text).split('\n');
		var found = null;
		for (var i = 0; i < lines.length; i++) {
			if (0 === lines[i].indexOf('data: ')) {
				found = lines[i].slice(6);
			}
		}
		if (!found) {
			return null;
		}
		try {
			return JSON.parse(found);
		} catch (e) {
			return null;
		}
	}

	function listen(root, url) {
		var source = new EventSource(url);

		source.addEventListener('seats', function (event) {
			try {
				apply(root, JSON.parse(event.data));
			} catch (e) {
				/* a malformed frame is skipped; the next one repaints */
			}
		});

		// The server closes every stream after its own limit and tells the browser to come back;
		// EventSource reconnects on its own, so there is nothing to do here but not fight it.
		source.addEventListener('error', function () {
			var status = root.querySelector('.cybevents-seatmap-status');
			if (status && source.readyState === EventSource.CLOSED) {
				status.textContent = status.dataset.static || '';
			}
		});
	}

	/** Repaint cells, labels and the table from one snapshot. */
	function apply(root, snapshot) {
		if (!snapshot || !snapshot.sessions) {
			return;
		}

		if (root.dataset.digest === snapshot.digest) {
			return;
		}
		root.dataset.digest = snapshot.digest;

		for (var i = 0; i < snapshot.sessions.length; i++) {
			var session = snapshot.sessions[i];
			var row = root.querySelector('.cybevents-seatmap-row[data-session-id="' + session.id + '"]');
			if (row) {
				row.dataset.taken = session.taken;
				paintSeats(row, session);
				paintLabel(row, session);
			}

			var tableRow = root.querySelector('[data-session-row="' + session.id + '"]');
			if (tableRow) {
				var takenCell = tableRow.querySelector('[data-cell="taken"]');
				var freeCell = tableRow.querySelector('[data-cell="free"]');
				if (takenCell) {
					takenCell.textContent = session.taken;
				}
				if (freeCell) {
					freeCell.textContent = session.free;
				}
			}
		}
	}

	function paintSeats(row, session) {
		var seats = row.querySelectorAll('.cybevents-seatmap-seat');
		for (var i = 0; i < seats.length; i++) {
			var taken = i < session.taken;
			seats[i].classList.toggle('is-taken', taken);
			seats[i].classList.toggle('is-free', !taken);
		}

		// Large sessions are drawn as a bar rather than as cells.
		var fill = row.querySelector('.cybevents-seatmap-bar-fill');
		var track = row.querySelector('.cybevents-seatmap-bar-track');
		if (fill && track && session.capacity > 0) {
			fill.setAttribute('width', String(Math.round(Number(track.getAttribute('width')) * (session.taken / session.capacity))));
		}
	}

	function paintLabel(row, session) {
		var label = row.querySelector('.cybevents-seatmap-label');
		if (!label) {
			return;
		}
		// The label is built by the server for the first paint; afterwards only the two numbers in it
		// change, so they are replaced in place and the wording (and its translation) stays the server's.
		label.textContent = label.textContent.replace(/\d+(\s*)(\S+)(\s*)\d+/, function (match) {
			return match.replace(/^\d+/, String(session.taken)).replace(/\d+$/, String(session.capacity));
		});
	}

	/** Clicking or pressing Enter on a session row books or releases a seat. */
	function wireBooking(root, eventId, token) {
		var rows = root.querySelectorAll('.cybevents-seatmap-row');
		for (var i = 0; i < rows.length; i++) {
			rows[i].classList.add('is-actionable');
			rows[i].addEventListener('click', function (e) { toggle(root, e.currentTarget, eventId, token); });
			rows[i].addEventListener('keydown', function (e) {
				if ('Enter' === e.key || ' ' === e.key) {
					e.preventDefault();
					toggle(root, e.currentTarget, eventId, token);
				}
			});
		}
	}

	function toggle(root, row, eventId, token) {
		var settings = window.cybeventsSettings || {};
		if (!settings.root) {
			return;
		}

		var sessionId = parseInt(row.dataset.sessionId, 10);
		var selected = [];
		var rows = root.querySelectorAll('.cybevents-seatmap-row.is-booked');
		for (var i = 0; i < rows.length; i++) {
			selected.push(parseInt(rows[i].dataset.sessionId, 10));
		}

		var index = selected.indexOf(sessionId);
		if (index === -1) {
			selected.push(sessionId);
		} else {
			selected.splice(index, 1);
		}

		var status = root.querySelector('.cybevents-seatmap-status');

		fetch(settings.root + '/book-sessions', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': settings.nonce || '' },
			body: JSON.stringify({ token: token, event_id: eventId, session_ids: selected })
		})
			.then(function (response) {
				return response.json().then(function (data) { return { status: response.status, data: data }; });
			})
			.then(function (result) {
				if (200 === result.status && result.data.success) {
					row.classList.toggle('is-booked');
				}
				if (status && result.data && result.data.message) {
					// Whatever the server says — including the waiting room's 503 — is what the page shows.
					status.textContent = result.data.message;
				}
			})
			.catch(function () {
				if (status) {
					status.textContent = status.dataset.static || '';
				}
			});
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
