/**
 * WM Cyber Events Core — Enterprise Admin Interactive JS
 *
 * Implements client-side table search/filters, ZUGFeRD XML modal inspector,
 * settings tab navigation, and DATEV export date helpers.
 *
 * @package CyberEvents
 */

document.addEventListener('DOMContentLoaded', () => {
	// 0. Modal focus management — the backdrop toggles `.is-open`; focus has to
	// follow the dialog in and return to the opener on close, or keyboard users
	// stay on the page behind the overlay (WCAG 2.4.3).
	const FOCUSABLE = 'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])';
	let lastFocus = null;

	const openModal = (modal) => {
		if (!modal) return;
		lastFocus = document.activeElement;
		modal.classList.add('is-open');
		const first = modal.querySelector('.cybevents-modal ' + FOCUSABLE) || modal.querySelector(FOCUSABLE);
		if (first) first.focus();
	};

	const closeModal = (modal) => {
		if (!modal || !modal.classList.contains('is-open')) return;
		modal.classList.remove('is-open');
		if (lastFocus && typeof lastFocus.focus === 'function' && document.contains(lastFocus)) {
			lastFocus.focus();
		}
		lastFocus = null;
	};

	// 1. Settings Tab Navigation
	const tabButtons = document.querySelectorAll('.cybevents-tab-btn');
	const tabPanes = document.querySelectorAll('.cybevents-tab-pane');

	tabButtons.forEach((btn) => {
		btn.addEventListener('click', (e) => {
			e.preventDefault();
			const targetTab = btn.getAttribute('data-tab');

			tabButtons.forEach((b) => b.classList.remove('is-active'));
			tabPanes.forEach((p) => p.classList.remove('is-active'));

			btn.classList.add('is-active');
			const activePane = document.getElementById(`tab-${targetTab}`);
			if (activePane) {
				activePane.classList.add('is-active');
			}
		});
	});

	// 2. Client-side Live Search in Attendees & Registrations Table
	const searchInput = document.getElementById('cybevents-table-search');
	const tableRows = document.querySelectorAll('.cybevents-table-body tr');

	if (searchInput && tableRows.length > 0) {
		searchInput.addEventListener('input', (e) => {
			const query = e.target.value.toLowerCase().trim();

			tableRows.forEach((row) => {
				const text = row.innerText.toLowerCase();
				if (text.includes(query)) {
					row.style.display = '';
				} else {
					row.style.display = 'none';
				}
			});
		});
	}

	// 3. Status Filter Pills
	const filterPills = document.querySelectorAll('.cybevents-filter-pill');
	if (filterPills.length > 0 && tableRows.length > 0) {
		filterPills.forEach((pill) => {
			pill.addEventListener('click', (e) => {
				e.preventDefault();
				filterPills.forEach((p) => p.classList.remove('is-active'));
				pill.classList.add('is-active');

				const status = pill.getAttribute('data-status');

				tableRows.forEach((row) => {
					if (status === 'all' || row.getAttribute('data-status') === status) {
						row.style.display = '';
					} else {
						row.style.display = 'none';
					}
				});
			});
		});
	}

	// 4. ZUGFeRD 2.3 XML Modal Viewer
	const xmlButtons = document.querySelectorAll('.cybevents-btn-view-xml');
	const modalBackdrop = document.getElementById('cybevents-xml-modal');
	const modalBody = document.getElementById('cybevents-xml-content');
	const modalCloseBtn = document.getElementById('cybevents-xml-modal-close');
	const copyXmlBtn = document.getElementById('cybevents-btn-copy-xml');

	if (modalBackdrop && modalBody) {
		xmlButtons.forEach((btn) => {
			btn.addEventListener('click', (e) => {
				e.preventDefault();
				const rawXml = btn.getAttribute('data-xml') || 'Keine XML-Daten verfügbar.';
				modalBody.textContent = rawXml;
				openModal(modalBackdrop);
			});
		});

		if (modalCloseBtn) {
			modalCloseBtn.addEventListener('click', () => {
				closeModal(modalBackdrop);
			});
		}

		modalBackdrop.addEventListener('click', (e) => {
			if (e.target === modalBackdrop) {
				closeModal(modalBackdrop);
			}
		});

		if (copyXmlBtn) {
			copyXmlBtn.addEventListener('click', () => {
				navigator.clipboard.writeText(modalBody.textContent).then(() => {
					const origText = copyXmlBtn.textContent;
					copyXmlBtn.textContent = 'Kopiert';
					setTimeout(() => {
						copyXmlBtn.textContent = origText;
					}, 2000);
				});
			});
		}
	}

	// 5. DATEV Quick Date Range Presets
	const presetBtns = document.querySelectorAll('.cybevents-date-preset');
	const inputFrom = document.getElementById('datev_date_from');
	const inputTo = document.getElementById('datev_date_to');

	if (presetBtns.length > 0 && inputFrom && inputTo) {
		presetBtns.forEach((btn) => {
			btn.addEventListener('click', (e) => {
				e.preventDefault();
				const preset = btn.getAttribute('data-preset');
				const now = new Date();

				if (preset === 'current_month') {
					const firstDay = new Date(now.getFullYear(), now.getMonth(), 1);
					inputFrom.value = firstDay.toISOString().split('T')[0];
					inputTo.value = now.toISOString().split('T')[0];
				} else if (preset === 'prev_month') {
					const firstDay = new Date(now.getFullYear(), now.getMonth() - 1, 1);
					const lastDay = new Date(now.getFullYear(), now.getMonth(), 0);
					inputFrom.value = firstDay.toISOString().split('T')[0];
					inputTo.value = lastDay.toISOString().split('T')[0];
				} else if (preset === 'year') {
					const firstDay = new Date(now.getFullYear(), 0, 1);
					inputFrom.value = firstDay.toISOString().split('T')[0];
					inputTo.value = now.toISOString().split('T')[0];
				}
			});
		});
	}

	// 6. DSGVO Export Modal & Checkbox Consent Interactivity
	const openExportBtn = document.getElementById('cybevents-btn-open-export');
	const exportModal = document.getElementById('cybevents-export-modal');
	const closeExportBtn = document.getElementById('cybevents-export-modal-close');
	const cancelExportBtn = document.getElementById('cybevents-btn-cancel-export');
	const consentCheckbox = document.getElementById('cybevents-dsgvo-export-consent');
	const confirmExportBtn = document.getElementById('cybevents-btn-confirm-export');

	if (openExportBtn && exportModal) {
		openExportBtn.addEventListener('click', (e) => {
			e.preventDefault();
			if (consentCheckbox) {
				consentCheckbox.checked = false;
			}
			if (confirmExportBtn) {
				confirmExportBtn.disabled = true;
			}
			openModal(exportModal);
		});

		const closeExportModal = () => {
			closeModal(exportModal);
		};

		if (closeExportBtn) {
			closeExportBtn.addEventListener('click', closeExportModal);
		}
		if (cancelExportBtn) {
			cancelExportBtn.addEventListener('click', closeExportModal);
		}
		exportModal.addEventListener('click', (e) => {
			if (e.target === exportModal) {
				closeExportModal();
			}
		});
		if (consentCheckbox && confirmExportBtn) {
			consentCheckbox.addEventListener('change', () => {
				confirmExportBtn.disabled = !consentCheckbox.checked;
			});
		}
	}

	// 7. Client-side Live Search for Dashboard Events Table
	const eventSearchInput = document.getElementById('cybevents-event-search');
	const eventRows = document.querySelectorAll('.cybevents-event-row');

	if (eventSearchInput && eventRows.length > 0) {
		eventSearchInput.addEventListener('input', (e) => {
			const query = e.target.value.toLowerCase().trim();

			eventRows.forEach((row) => {
				const text = row.innerText.toLowerCase();
				if (text.includes(query)) {
					row.style.display = '';
				} else {
					row.style.display = 'none';
				}
			});
		});
	}

	// 8. Event Quick-Edit Modal
	const quickEditButtons = document.querySelectorAll('.cybevents-btn-quick-edit');
	const eventEditModal = document.getElementById('cybevents-event-edit-modal');
	const eventEditClose = document.getElementById('cybevents-event-edit-close');
	const eventEditCancel = document.getElementById('cybevents-event-edit-cancel');

	if (quickEditButtons.length > 0 && eventEditModal) {
		quickEditButtons.forEach((btn) => {
			btn.addEventListener('click', (e) => {
				e.preventDefault();
				const evDataStr = btn.getAttribute('data-event');
				if (!evDataStr) return;

				try {
					const ev = JSON.parse(evDataStr);
					const fieldId = document.getElementById('edit_event_id');
					const fieldPostId = document.getElementById('edit_wp_post_id');
					const fieldTitle = document.getElementById('edit_event_title');
					const fieldSlug = document.getElementById('edit_event_slug');
					const fieldLocation = document.getElementById('edit_event_location');
					const fieldStartDate = document.getElementById('edit_event_start_date');
					const fieldEndDate = document.getElementById('edit_event_end_date');
					const fieldCapacity = document.getElementById('edit_event_max_capacity');
					const fieldPrice = document.getElementById('edit_event_ticket_price');
					const fieldPaid = document.getElementById('edit_event_is_paid');
					const fieldStatus = document.getElementById('edit_event_status');
					const gutenbergLink = document.getElementById('edit_event_gutenberg_link');

					if (fieldId) fieldId.value = ev.id || 0;
					if (fieldPostId) fieldPostId.value = ev.wp_post_id || 0;
					if (fieldTitle) fieldTitle.value = ev.title || '';
					if (fieldSlug) fieldSlug.value = ev.slug || '';
					if (fieldLocation) fieldLocation.value = ev.location || '';
					if (fieldStartDate) fieldStartDate.value = (ev.start_date || '').replace(' ', 'T').substring(0, 16);
					if (fieldEndDate) fieldEndDate.value = (ev.end_date || '').replace(' ', 'T').substring(0, 16);
					if (fieldCapacity) fieldCapacity.value = ev.max_capacity || 500;
					if (fieldPrice) fieldPrice.value = parseFloat(ev.ticket_price || 0).toFixed(2);
					if (fieldPaid) fieldPaid.checked = parseInt(ev.is_paid, 10) === 1;
					if (fieldStatus) fieldStatus.value = ev.status || 'active';

					if (gutenbergLink && ev.edit_url) {
						gutenbergLink.href = ev.edit_url;
					}

					openModal(eventEditModal);
				} catch (err) {
					console.error('Fehler beim Öffnen der Schnellbearbeitung:', err);
				}
			});
		});

		const closeEditModal = () => {
			closeModal(eventEditModal);
		};

		if (eventEditClose) eventEditClose.addEventListener('click', closeEditModal);
		if (eventEditCancel) eventEditCancel.addEventListener('click', closeEditModal);

		eventEditModal.addEventListener('click', (e) => {
			if (e.target === eventEditModal) {
				closeEditModal();
			}
		});
	}

	// 9. Attendee / Registration Edit Modal
	const editRegButtons = document.querySelectorAll('.cybevents-btn-edit-registration');
	const regEditModal = document.getElementById('cybevents-registration-edit-modal');
	const regEditClose = document.getElementById('cybevents-reg-edit-close');
	const regEditCancel = document.getElementById('cybevents-reg-edit-cancel');

	if (editRegButtons.length > 0 && regEditModal) {
		editRegButtons.forEach((btn) => {
			btn.addEventListener('click', (e) => {
				e.preventDefault();
				const regDataStr = btn.getAttribute('data-registration');
				if (!regDataStr) return;

				try {
					const reg = JSON.parse(regDataStr);
					const fieldId = document.getElementById('edit_reg_id');
					const fieldEventId = document.getElementById('edit_reg_event_id');
					const fieldFirstName = document.getElementById('edit_reg_first_name');
					const fieldLastName = document.getElementById('edit_reg_last_name');
					const fieldEmail = document.getElementById('edit_reg_email');
					const fieldCompany = document.getElementById('edit_reg_company');
					const fieldStatus = document.getElementById('edit_reg_status');
					const fieldCheckedIn = document.getElementById('edit_reg_checked_in');
					const fieldInvoiceNum = document.getElementById('edit_reg_invoice_number');
					const fieldPaymentStatus = document.getElementById('edit_reg_payment_status');
					const fieldConsentPriv = document.getElementById('edit_reg_consent_privacy');
					const fieldConsentPhoto = document.getElementById('edit_reg_consent_photo');
					const fieldAvatarUrl = document.getElementById('edit_reg_avatar_url');
					const fieldDeleteAvatar = document.getElementById('edit_reg_delete_avatar');
					const avatarImg = document.getElementById('edit_reg_avatar_img');
					const avatarInitials = document.getElementById('edit_reg_avatar_initials');
					const btnDeleteAvatar = document.getElementById('edit_reg_btn_delete_avatar');
					const fileInput = document.getElementById('edit_reg_avatar_file');

					if (fieldId) fieldId.value = reg.id || 0;
					if (fieldEventId) fieldEventId.value = reg.event_id || 0;
					if (fieldFirstName) fieldFirstName.value = reg.first_name || '';
					if (fieldLastName) fieldLastName.value = reg.last_name || '';
					if (fieldEmail) fieldEmail.value = reg.email || '';
					if (fieldCompany) fieldCompany.value = reg.company || '';
					if (fieldStatus) fieldStatus.value = reg.status || 'confirmed';
					if (fieldCheckedIn) fieldCheckedIn.value = parseInt(reg.checked_in, 10) === 1 ? '1' : '0';
					if (fieldInvoiceNum) fieldInvoiceNum.value = reg.invoice_number || '';
					if (fieldPaymentStatus) fieldPaymentStatus.value = reg.payment_status || 'free';
					if (fieldConsentPriv) fieldConsentPriv.checked = parseInt(reg.consent_privacy, 10) === 1;
					if (fieldConsentPhoto) fieldConsentPhoto.checked = parseInt(reg.consent_photo, 10) === 1;

					// Avatar handling
					if (fileInput) fileInput.value = '';
					if (fieldDeleteAvatar) fieldDeleteAvatar.value = '0';
					if (fieldAvatarUrl) fieldAvatarUrl.value = reg.avatar_url || '';

					const initials = ((reg.first_name ? reg.first_name[0] : '') + (reg.last_name ? reg.last_name[0] : '')).toUpperCase() || '?';

					if (reg.avatar_url) {
						if (avatarImg) {
							avatarImg.src = reg.avatar_url;
							avatarImg.style.display = 'block';
						}
						if (avatarInitials) avatarInitials.style.display = 'none';
						if (btnDeleteAvatar) btnDeleteAvatar.style.display = 'inline-block';
					} else {
						if (avatarImg) avatarImg.style.display = 'none';
						if (avatarInitials) {
							avatarInitials.textContent = initials;
							avatarInitials.style.display = 'inline-block';
						}
						if (btnDeleteAvatar) btnDeleteAvatar.style.display = 'none';
					}

					if (fileInput) {
						fileInput.onchange = (fe) => {
							const f = fe.target.files[0];
							if (f) {
								const reader = new FileReader();
								reader.onload = (re) => {
									if (avatarImg) {
										avatarImg.src = re.target.result;
										avatarImg.style.display = 'block';
									}
									if (avatarInitials) avatarInitials.style.display = 'none';
									if (btnDeleteAvatar) btnDeleteAvatar.style.display = 'inline-block';
									if (fieldDeleteAvatar) fieldDeleteAvatar.value = '0';
								};
								reader.readAsDataURL(f);
							}
						};
					}

					if (btnDeleteAvatar) {
						btnDeleteAvatar.onclick = () => {
							if (fieldDeleteAvatar) fieldDeleteAvatar.value = '1';
							if (fieldAvatarUrl) fieldAvatarUrl.value = '';
							if (fileInput) fileInput.value = '';
							if (avatarImg) avatarImg.style.display = 'none';
							if (avatarInitials) {
								avatarInitials.textContent = initials;
								avatarInitials.style.display = 'inline-block';
							}
							btnDeleteAvatar.style.display = 'none';
						};
					}

					openModal(regEditModal);
				} catch (err) {
					console.error('Fehler beim Öffnen der Teilnehmerbearbeitung:', err);
				}
			});
		});

		const closeRegModal = () => {
			closeModal(regEditModal);
		};

		if (regEditClose) regEditClose.addEventListener('click', closeRegModal);
		if (regEditCancel) regEditCancel.addEventListener('click', closeRegModal);

		regEditModal.addEventListener('click', (e) => {
			if (e.target === regEditModal) {
				closeRegModal();
			}
		});
	}

	// 10. Theme Studio Preset Card Selector
	const themeRadios = document.querySelectorAll('input[name="theme_preset"]');
	const themeCards = document.querySelectorAll('.cybevents-theme-card');

	if (themeRadios.length > 0) {
		themeRadios.forEach((radio) => {
			radio.addEventListener('change', () => {
				themeCards.forEach((c) => c.classList.remove('is-selected'));
				const parentCard = radio.closest('.cybevents-theme-card');
				if (parentCard) {
					parentCard.classList.add('is-selected');
				}
			});
		});
	}

	// 11. Global Keyboard Accessibility: Close Modals on ESC
	document.addEventListener('keydown', (e) => {
		if (e.key === 'Escape' || e.key === 'Esc') {
			const openModals = document.querySelectorAll('.cybevents-modal-backdrop.is-open');
			openModals.forEach((m) => closeModal(m));
		}
	});
});
