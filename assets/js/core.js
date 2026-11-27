(function () {
	"use strict";
	
	let prefersReducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
	
	/* ---------- Scroll reveal ---------- */
	let revealEls = document.querySelectorAll(".gh-reveal");
	if ("IntersectionObserver" in window && !prefersReducedMotion) {
		let io = new IntersectionObserver(
			function (entries) {
				entries.forEach(function (entry) {
					if (entry.isIntersecting) {
						entry.target.classList.add("in-view");
						io.unobserve(entry.target);
					}
				});
			},
			{ threshold: 0.15 }
		);
		revealEls.forEach(function (el) { io.observe(el); });
	} else {
		revealEls.forEach(function (el) { el.classList.add("in-view"); });
	}
})();