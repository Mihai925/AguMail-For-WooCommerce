/*
 * AguMail settings page: keeps the store data sync's progress current while
 * it runs. Every few seconds it asks the settings page's own admin-ajax action
 * (bulk_sync_progress, nonce-checked for a store manager) for the progress
 * block, rendered and escaped by PHP, and swaps it in. It stops once the sync
 * is no longer running, waits longer after a failed call, and skips its turn
 * while the tab is hidden. A 403 means the session or the page's nonce
 * expired: polling stops, and the page says to reload. Nothing here talks to
 * AguMail.
 */
(function () {
	'use strict';

	var config = window.agumailAdmin || {};
	var interval = Number(config.interval) > 0 ? Number(config.interval) : 3000;
	var timer = null;

	function panel() {
		return document.querySelector('[data-agumail-bulk]');
	}

	function running(element) {
		return !!element && element.getAttribute('data-status') === 'running';
	}

	function stopWithReload() {
		window.clearTimeout(timer);
		var element = panel();
		if (!element || !config.reload || element.querySelector('.agumail-bulk-reload')) {
			return;
		}
		var note = document.createElement('p');
		note.className = 'agumail-bulk-reload';
		note.textContent = config.reload;
		element.appendChild(note);
	}

	function later(delay) {
		window.clearTimeout(timer);
		timer = window.setTimeout(poll, delay);
	}

	function poll() {
		var element = panel();
		if (!running(element) || !config.ajaxUrl || !config.nonce || !window.fetch) {
			return;
		}
		if (document.hidden) {
			later(interval);
			return;
		}
		var body = new window.FormData();
		body.append('action', 'agumail');
		body.append('agumail_action', 'bulk_sync_progress');
		body.append('_ajax_nonce', config.nonce);
		window.fetch(config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (response) {
				if (response.status === 403) {
					stopWithReload();
					return false;
				}
				return response.json();
			})
			.then(function (json) {
				if (json === false) {
					return;
				}
				var data = json && json.success ? json.data : null;
				if (!data || typeof data.html !== 'string') {
					later(interval * 4);
					return;
				}
				var current = panel();
				if (current) {
					current.outerHTML = data.html;
				}
				if (data.status === 'running') {
					later(interval);
				}
			})
			.catch(function () {
				later(interval * 4);
			});
	}

	function start() {
		if (running(panel())) {
			later(interval);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
