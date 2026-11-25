/**
 * WM Cyber Events Core — public scripts: registration form, session selector, countdown and the
 * Calendar Views controls. Every value from the REST routes goes through escapeHtml().
 *
 * @package CyberEvents
 */

 // Decorative icons (aria-hidden, currentColor), the same shapes as CyberEvents_Icons in PHP.
 const CYBEVENTS_ICON_SHAPES = {
 clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
 building: '<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M8 7h2M14 7h2M8 11h2M14 11h2M8 15h2M14 15h2M11 21v-3h2v3"/>',
 mic: '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/>',
 check: '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
 alert: '<path d="M12 3.5L22 20H2z"/><path d="M12 10v4.5M12 17.5v.5"/>',
 };

 function cybeventsIcon(name, size = 16) {
 const shape = CYBEVENTS_ICON_SHAPES[name];
 if (!shape) return '';
 return `<svg class="cybevents-icon" width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">${shape}</svg>`;
 }

document.addEventListener('DOMContentLoaded', () => {
	const getApiConfig = () => {
		const s = window.cybeventsSettings || window.cybeventsData || {};
		return {
			// The localised root already ends in cybevents/v1; routes are appended as /register etc.
			root: (s.root || s.restUrl || '/wp-json/cybevents/v1').replace(/\/+$/, ''),
			nonce: s.nonce || '',
		};
	};

	// 0. Live Avatar Upload Preview Handler
	const avatarInputs = document.querySelectorAll('.cybevents-file-input');
	avatarInputs.forEach((input) => {
		input.addEventListener('change', (e) => {
			const file = e.target.files[0];
			const container = input.closest('.cybevents-form-container') || document;
			const previewImg = container.querySelector('#cybevents-avatar-preview-img');
			const placeholder = container.querySelector('#cybevents-avatar-placeholder');
			const hint = container.querySelector('#cybevents-file-hint');

			if (file) {
				if (file.size > 5 * 1024 * 1024) {
					alert('Das Profilbild darf maximal 5 MB groß sein.');
					input.value = '';
					return;
				}
				const reader = new FileReader();
				reader.onload = (re) => {
					if (previewImg) {
						previewImg.src = re.target.result;
						previewImg.style.display = 'block';
					}
					if (placeholder) {
						placeholder.style.display = 'none';
					}
					if (hint) {
						hint.textContent = file.name;
						hint.style.color = '#10b981';
					}
				};
				reader.readAsDataURL(file);
			}
		});
	});

	// 1. Interactive Registration Form Handler
	const regForms = document.querySelectorAll('.cybevents-form');
	regForms.forEach((form) => {
		form.addEventListener('submit', async (e) => {
			e.preventDefault();

			const feedback = form.querySelector('.cybevents-feedback');
			const submitBtn = form.querySelector('button[type="submit"]');

			if (feedback) {
				feedback.innerHTML = '<div class="cybevents-notice" style="background:#eff6ff;color:#1d4ed8;border-left:4px solid #2563eb;">Anmeldung wird gesendet …</div>';
			}
			if (submitBtn) {
				submitBtn.disabled = true;
			}

			const formData = new FormData(form);
			const config = getApiConfig();

			try {
				const response = await fetch(config.root + '/register', {
					method: 'POST',
					headers: {
						'X-WP-Nonce': config.nonce,
					},
					body: formData,
				});

				const result = await response.json();

				if (response.ok && result.success) {
					if (feedback) {
						feedback.innerHTML = `
							<div class="cybevents-notice" style="background:#ecfdf5;color:#065f46;border-left:4px solid #059669;padding:18px;border-radius:8px;">
								<h3 style="margin:0 0 6px 0;font-size:16px;">${escapeHtml(result.message)}</h3>
								<p style="margin:0;font-size:13px;">Sie erhalten eine E-Mail mit einem Bestätigungslink. Erst nach der Bestätigung ist die Anmeldung gültig und Sie können Ihre Sessions wählen.</p>
							</div>
						`;
					}
					form.reset();
				} else {
					if (feedback) {
						feedback.innerHTML = `<div class="cybevents-notice" style="background:#fef2f2;color:#991b1b;border-left:4px solid #dc2626;padding:16px;border-radius:8px;">${escapeHtml(result.message || 'Ein Fehler ist aufgetreten.')}</div>`;
					}
				}
			} catch (err) {
				if (feedback) {
					feedback.innerHTML = '<div class="cybevents-notice" style="background:#fef2f2;color:#991b1b;border-left:4px solid #dc2626;padding:16px;border-radius:8px;">Netzwerkfehler. Bitte überprüfen Sie Ihre Verbindung.</div>';
				}
			} finally {
				if (submitBtn) {
					submitBtn.disabled = false;
				}
			}
		});
	});

	// 2. Tokenized Session Selector App (Live Realtime Sync)
	const sessionApp = document.getElementById('cybevents-session-app');
	if (sessionApp) {
		const urlParams = new URLSearchParams(window.location.search);
		const token = urlParams.get('token');

		// Sync initial count in static PHP-rendered mode if present
		updateSelectedCounter();
		bindSessionCheckboxes();

		if (token) {
			loadSessionSelector(sessionApp, token, getApiConfig());
		}
	}

	// 3. Live Countdown Timers for Event Hero Blocks
	const countdownBoxes = document.querySelectorAll('.cybevents-countdown-box');
	countdownBoxes.forEach((box) => {
		const targetStr = box.getAttribute('data-target-date');
		if (!targetStr) return;

		const targetDate = new Date(targetStr).getTime();
		const daysEl = box.querySelector('#cyb-cd-days');
		const hoursEl = box.querySelector('#cyb-cd-hours');
		const minsEl = box.querySelector('#cyb-cd-mins');
		const secsEl = box.querySelector('#cyb-cd-secs');

		const updateCountdown = () => {
			const now = new Date().getTime();
			const distance = targetDate - now;

			if (distance < 0) {
				box.innerHTML = '<div class="cybevents-countdown-title">Die Veranstaltung hat begonnen.</div>';
				return;
			}

			const days = Math.floor(distance / (1000 * 60 * 60 * 24));
			const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
			const mins = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
			const secs = Math.floor((distance % (1000 * 60)) / 1000);

			if (daysEl) daysEl.textContent = String(days).padStart(2, '0');
			if (hoursEl) hoursEl.textContent = String(hours).padStart(2, '0');
			if (minsEl) minsEl.textContent = String(mins).padStart(2, '0');
			if (secsEl) secsEl.textContent = String(secs).padStart(2, '0');
		};

		updateCountdown();
		setInterval(updateCountdown, 1000);
	});
});

function updateSelectedCounter() {
	const counter = document.getElementById('cybevents-selected-count');
	if (!counter) return;
	const checked = document.querySelectorAll('input[name="sessions[]"]:checked');
	counter.textContent = checked.length;
}

function showToast(message, type = 'error') {
	const existingToast = document.querySelector('.cybevents-toast');
	if (existingToast) {
		existingToast.remove();
	}

	const toast = document.createElement('div');
	toast.className = `cybevents-toast cybevents-toast--${type}`;
	toast.innerHTML = `
		<span style="font-size:18px;">${cybeventsIcon(type === 'error' ? 'alert' : 'check', 18)}</span>
		<div>
			<strong style="display:block;font-size:14px;color:#0f172a;margin-bottom:2px;">${type === 'error' ? 'Achtung' : 'Erfolg'}</strong>
			<span style="font-size:13px;color:#475569;">${escapeHtml(message)}</span>
		</div>
	`;
	document.body.appendChild(toast);

	setTimeout(() => {
		toast.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
		toast.style.opacity = '0';
		toast.style.transform = 'translateX(100%)';
		setTimeout(() => toast.remove(), 300);
	}, 4000);
}

function bindSessionCheckboxes() {
	const checkboxes = document.querySelectorAll('input[name="sessions[]"]');
	checkboxes.forEach((cb) => {
		cb.addEventListener('change', () => {
			const card = cb.closest('.cybevents-session-card');
			if (card) {
				if (cb.checked) {
					card.classList.add('is-booked');
				} else {
					card.classList.remove('is-booked');
				}
			}
			updateSelectedCounter();
		});
	});
}

async function loadSessionSelector(container, token, config) {
	try {
		const response = await fetch(`${config.root}/session-selection?token=${encodeURIComponent(token)}`);
		const data = await response.json();

		if (!response.ok || !data.success) {
			container.innerHTML = `<div class="cybevents-notice" style="background:#fef2f2;color:#991b1b;border-left:4px solid #dc2626;padding:18px;border-radius:8px;">${escapeHtml(data.message || 'Fehler beim Laden der Sessions.')}</div>`;
			return;
		}

		// Field names as the REST route returns them (until 2026-09-15 the script read booked_ids,
		// participant.name and freie_plaetze, which the route never sent).
		const { participant, sessions } = data;
		const booked_ids = (data.booked_sessions || []).map((id) => parseInt(id, 10));
		const participantName = `${participant.first_name || ''} ${participant.last_name || ''}`.trim();

		let html = `
			<div class="cybevents-participant-welcome">
				<div class="cybevents-participant-info">
					<h2>Willkommen, ${escapeHtml(participantName)}!</h2>
					<p>Wählen Sie Ihre Sessions. Ein Platz wird beim Speichern vergeben, solange die Session nicht ausgebucht ist.</p>
				</div>
				<div>
					<span class="cybevents-status-chip">${cybeventsIcon('check')} Anmeldung bestätigt</span>
				</div>
			</div>

			<form id="cybevents-session-form" class="cybevents-session-form">
				<div class="cybevents-sessions-list">
		`;

		sessions.forEach((s) => {
			const isBooked = booked_ids.includes(parseInt(s.id, 10));
			const freeSeats = parseInt(s.verfuegbar, 10);
			const isFull = freeSeats <= 0 && !isBooked;
			const timeStr = `${formatTime(s.start_time)} – ${formatTime(s.end_time)} Uhr`;

			html += `
				<div class="cybevents-session-card ${isBooked ? 'is-booked' : ''} ${isFull ? 'is-full' : ''}" id="session-card-${escapeHtml(s.id)}">
					<div class="cybevents-session-header">
						<div>
							<h3 class="cybevents-session-title">${escapeHtml(s.title)}</h3>
							<div class="cybevents-session-meta-strip">
								<span class="cybevents-meta-pill">${cybeventsIcon('clock')} ${escapeHtml(timeStr)}</span>
								${s.raum ? `<span class="cybevents-meta-pill">${cybeventsIcon('building')} ${escapeHtml(s.raum)}</span>` : ''}
								${s.speaker ? `<span class="cybevents-meta-pill">${cybeventsIcon('mic')} ${escapeHtml(s.speaker)}</span>` : ''}
							</div>
						</div>
						<div>
							<span class="cybevents-capacity-badge ${isFull ? 'cybevents-capacity-badge--full' : ''}">
								${isFull ? 'Ausgebucht' : (freeSeats === 1 ? '1 freier Platz' : `${escapeHtml(freeSeats)} freie Plätze`)}
							</span>
						</div>
					</div>

					${s.description ? `<p class="cybevents-session-desc">${escapeHtml(s.description)}</p>` : ''}

					<label class="cybevents-consent-card" for="session-cb-${escapeHtml(s.id)}" style="margin-bottom: 0;">
						<input type="checkbox" id="session-cb-${escapeHtml(s.id)}" name="sessions[]" value="${escapeHtml(s.id)}" 
							class="cybevents-checkbox session-checkbox" 
							data-start="${escapeHtml(s.start_time)}" 
							data-end="${escapeHtml(s.end_time)}"
							${isBooked ? 'checked' : ''} 
							${isFull ? 'disabled' : ''} 
						/>
						<div class="cybevents-consent-text">
							<strong>${isBooked ? 'Für diese Session eingetragen' : 'Diesen Programmpunkt auswählen'}</strong>
							<span>Der Platz wird beim Speichern vergeben, solange die Session nicht ausgebucht ist.</span>
						</div>
					</label>
				</div>
			`;
		});

		html += `
				</div>

				<div class="cybevents-floating-dock" id="cybevents-action-dock">
					<div class="cybevents-dock-summary">
						<span class="cybevents-dock-pill" id="cybevents-selected-count">${booked_ids.length}</span>
						<span>Sessions ausgewählt</span>
					</div>
					<button type="submit" class="cybevents-dock-btn" id="cybevents-save-sessions-btn">
						Auswahl verbindlich speichern
					</button>
				</div>

				<div id="cybevents-session-feedback" class="cybevents-feedback" role="alert" aria-live="polite"></div>
			</form>
		`;

		container.innerHTML = html;
		bindSessionCheckboxes();
		updateSelectedCounter();

		// Bind Form Submit
		const sessionForm = document.getElementById('cybevents-session-form');
		sessionForm.addEventListener('submit', async (e) => {
			e.preventDefault();
			const submitBtn = document.getElementById('cybevents-save-sessions-btn');

			if (submitBtn) {
				submitBtn.disabled = true;
				submitBtn.textContent = 'Wird gespeichert …';
			}

			const checkedBoxes = sessionForm.querySelectorAll('input[name="sessions[]"]:checked');
			const session_ids = Array.from(checkedBoxes).map((cb) => parseInt(cb.value, 10));

			try {
				const saveRes = await fetch(`${config.root}/book-sessions`, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						token: token,
						session_ids: session_ids,
					}),
				});

				const saveResult = await saveRes.json();

				if (saveRes.ok && saveResult.success) {
					showToast('Ihre Auswahl wurde gespeichert.', 'success');
					setTimeout(() => loadSessionSelector(container, token, config), 1200);
				} else {
					showToast(saveResult.message || 'Fehler beim Speichern der Auswahl.', 'error');
				}
			} catch (err) {
				showToast('Verbindungsfehler beim Speichern.', 'error');
			} finally {
				if (submitBtn) {
					submitBtn.disabled = false;
					submitBtn.textContent = 'Auswahl verbindlich speichern';
				}
			}
		});
	} catch (e) {
		container.innerHTML = '<div class="cybevents-notice" style="background:#fef2f2;color:#991b1b;border-left:4px solid #dc2626;padding:18px;border-radius:8px;">Verbindungsfehler beim Laden der Sessions.</div>';
	}
}

function escapeHtml(str) {
	if (str === null || str === undefined) return '';
	return String(str).replace(/[&<>'"]/g, function(tag) {
		var chars = { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' };
		return chars[tag] || tag;
	});
}

function formatTime(dtStr) {
	if (!dtStr) return '';
	const parts = dtStr.split(' ');
	if (parts.length > 1) {
		return parts[1].substring(0, 5);
	}
	return dtStr;
}

// ==========================================
// Multi-View Calendar Suite Interactive API
// ==========================================
window.cybSwitchView = function(wrapId, viewName) {
	var wrap = document.getElementById(wrapId);
	if (!wrap) return;
	wrap.setAttribute('data-current-view', viewName);
	
	// Update buttons
	var btns = wrap.querySelectorAll('.cybevents-view-btn');
	btns.forEach(function(btn) {
		var isActive = (btn.getAttribute('data-view') === viewName);
		btn.classList.toggle('is-active', isActive);
		btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
	});

	// Update panes
	var panes = wrap.querySelectorAll('.cybevents-view-pane');
	panes.forEach(function(p) {
		p.classList.remove('is-active');
	});
	var target = document.getElementById(wrapId + '-view-' + viewName);
	if (target) target.classList.add('is-active');
};

window.cybNavigatePeriod = function(wrapId, delta) {
	var wrap = document.getElementById(wrapId);
	var label = document.getElementById(wrapId + '-period-label');
	if (!wrap) return;

	var now = new Date();
	var year = parseInt(wrap.getAttribute('data-year') || String(now.getFullYear()), 10);
	var month = parseInt(wrap.getAttribute('data-month') || String(now.getMonth() + 1), 10);
	if (isNaN(year) || isNaN(month)) {
		year = now.getFullYear();
		month = now.getMonth() + 1;
	}

	var nextDate = new Date(year, month - 1 + delta, 1);
	var nextYear = nextDate.getFullYear();
	var nextMonth = nextDate.getMonth() + 1;
	var nextYm = nextYear + '-' + String(nextMonth).padStart(2, '0');

	wrap.setAttribute('data-year', String(nextYear));
	wrap.setAttribute('data-month', String(nextMonth));

	if (label) {
		try {
			var locale = document.documentElement.lang || 'de-DE';
			label.textContent = new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric' }).format(nextDate);
		} catch (e) {
			label.textContent = nextYm;
		}
	}

	if (window.location && /^https?:$/.test(window.location.protocol)) {
		var url = new URL(window.location.href);
		url.searchParams.set('cyb_month', nextYm);
		window.location.href = url.toString();
	}
};

window.cybFilterKeyword = function(wrapId, val) {
	var q = (val || '').toLowerCase().trim();
	var wrap = document.getElementById(wrapId);
	if (!wrap) return;
	var cards = wrap.querySelectorAll('.cybevents-event-chip, .cybevents-week-event-card, .cybevents-list-card, .cybevents-photo-card, .cybevents-agenda-item, .cybevents-track-slot-card');
	cards.forEach(function(c) {
		c.style.display = (c.textContent.toLowerCase().indexOf(q) !== -1) ? '' : 'none';
	});
};

window.cybFilterTrack = function(wrapId, track) {
	var wrap = document.getElementById(wrapId);
	if (!wrap) return;
	var items = wrap.querySelectorAll('.cybevents-event-chip, .cybevents-week-event-card, .cybevents-list-card, .cybevents-photo-card, .cybevents-agenda-item, .cybevents-track-slot-card');
	items.forEach(function(el) {
		if (track === 'all') {
			el.style.opacity = '1';
			el.style.filter = 'none';
		} else {
			var matches = (el.getAttribute('data-event-id') === track) || el.classList.contains('cybevents-track--' + track);
			el.style.opacity = matches ? '1' : '0.25';
			el.style.filter = matches ? 'none' : 'grayscale(100%)';
		}
	});
};

window.cybFilterRoom = function(wrapId, room) {
	var wrap = document.getElementById(wrapId);
	if (!wrap) return;
	var items = wrap.querySelectorAll('.cybevents-event-chip, .cybevents-week-event-card, .cybevents-photo-card, .cybevents-agenda-item, .cybevents-track-slot-card');
	items.forEach(function(el) {
		if (room === 'all') {
			el.style.opacity = '1';
			el.style.filter = 'none';
		} else {
			var matches = (el.getAttribute('data-room') === room);
			el.style.opacity = matches ? '1' : '0.25';
			el.style.filter = matches ? 'none' : 'grayscale(100%)';
		}
	});
	if (room !== 'all') {
		window.cybSelectRoom(null, room, wrapId);
	}
};

window.cybSelectRoom = function(btn, roomKey, wrapId) {
	var container = btn ? btn.closest('.cybevents-multi-view-wrapper') : document.getElementById(wrapId);
	if (!container) return;

	var btns = container.querySelectorAll('.cybevents-room-btn');
	var activeLabel = '';
	btns.forEach(function(b) {
		var key = b.getAttribute('data-room-key');
		var isMatch = (btn && b === btn) || (!btn && key === roomKey);
		b.classList.toggle('is-active', Boolean(isMatch));
		if (isMatch) {
			activeLabel = b.getAttribute('data-room-label') || b.textContent.trim();
		}
	});

	var nameEl = container.querySelector('.cybevents-room-badge');
	if (nameEl && activeLabel) {
		nameEl.textContent = activeLabel;
	}

	var panels = container.querySelectorAll('.cybevents-room-panel');
	panels.forEach(function(panel) {
		var panelKey = panel.getAttribute('data-room-panel');
		panel.hidden = (roomKey !== 'all' && panelKey !== roomKey);
	});
};

window.cybSwitchTimelineDay = function(btn, dayId) {
	var wrap = btn.closest('.cybevents-day-timeline-wrap');
	if (!wrap) return;
	var pills = wrap.querySelectorAll('.cybevents-day-pill');
	pills.forEach(function(p) { p.classList.remove('is-active'); });
	btn.classList.add('is-active');

	var panels = wrap.querySelectorAll('.cybevents-day-panel');
	panels.forEach(function(panel) {
		panel.hidden = (panel.getAttribute('data-day-panel') !== dayId);
	});
};

window.cybOpenDetailFromElement = function(wrapId, el) {
	if (!el) return;
	window.cybOpenDetail(
		wrapId,
		el.getAttribute('data-detail-title') || '',
		el.getAttribute('data-detail-time') || '',
		el.getAttribute('data-detail-room') || '',
		el.getAttribute('data-detail-speaker') || '',
		el.getAttribute('data-detail-price') || '',
		el.getAttribute('data-detail-link') || '#cybevents-registration-form'
	);
};

window.cybOpenDetail = function(wrapId, title, time, room, spk, price, link) {
	var modal = document.getElementById(wrapId + '-modal');
	if (!modal) return;
	var titleEl = document.getElementById(wrapId + '-modal-title');
	var timeEl = document.getElementById(wrapId + '-modal-time');
	var roomEl = document.getElementById(wrapId + '-modal-room');
	var speakerEl = document.getElementById(wrapId + '-modal-speaker');
	var priceEl = document.getElementById(wrapId + '-modal-price');
	var ctaEl = document.getElementById(wrapId + '-modal-cta');

	if (titleEl) titleEl.textContent = title;
	if (timeEl) timeEl.textContent = time;
	if (roomEl) roomEl.textContent = room;
	if (speakerEl) speakerEl.textContent = spk;
	if (priceEl) priceEl.textContent = price;
	if (ctaEl && link) ctaEl.setAttribute('href', link);
	modal.style.display = 'flex';
};

window.cybCloseModal = function(wrapId) {
	var modal = document.getElementById(wrapId + '-modal');
	if (modal) modal.style.display = 'none';
};
