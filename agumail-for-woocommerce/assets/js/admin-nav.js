/*
 * AguMail settings page: marks the section in view in the side nav, so the
 * merchant can see where they are on a long page. The nav's links are plain
 * anchors and work without this. Nothing here talks to AguMail.
 */
(function () {
	'use strict';

	// A section counts as in view once its top passes this far below the window's top.
	var OFFSET = 120;

	function start() {
		var nav = document.querySelector('.agumail-nav');
		var sections = Array.prototype.slice.call(document.querySelectorAll('.agumail-settings .agumail-section'));
		if (!nav || !sections.length) {
			return;
		}
		var links = {};
		Array.prototype.forEach.call(nav.querySelectorAll('a[href^="#agumail-section-"]'), function (link) {
			links[link.getAttribute('href').slice(1)] = link;
		});
		// The section last picked in the nav: at the foot of the page, where the
		// last few sections cannot reach the top, it stays marked.
		var picked = null;
		var queued = false;

		function mark(id) {
			Object.keys(links).forEach(function (key) {
				var on = key === id;
				links[key].classList.toggle('is-active', on);
				if (on) {
					links[key].setAttribute('aria-current', 'true');
				} else {
					links[key].removeAttribute('aria-current');
				}
			});
		}

		function update() {
			queued = false;
			var root = document.documentElement;
			var atFoot = window.innerHeight + window.pageYOffset >= root.scrollHeight - 4;
			var current = sections[0];
			if (atFoot) {
				var chosen = picked && document.getElementById(picked);
				current = chosen && chosen.getBoundingClientRect().top < window.innerHeight ? chosen : sections[sections.length - 1];
			} else {
				sections.forEach(function (section) {
					if (section.getBoundingClientRect().top <= OFFSET) {
						current = section;
					}
				});
			}
			mark(current.id);
		}

		function queue() {
			if (!queued) {
				queued = true;
				window.requestAnimationFrame(update);
			}
		}

		nav.addEventListener('click', function (event) {
			var link = event.target.closest ? event.target.closest('a[href^="#agumail-section-"]') : null;
			if (link) {
				picked = link.getAttribute('href').slice(1);
				queue();
			}
		});
		window.addEventListener('scroll', queue, { passive: true });
		window.addEventListener('resize', queue);
		update();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
