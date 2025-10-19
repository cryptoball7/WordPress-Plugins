/**
 * Example public script to fetch aggregated data for a single post and render a small pie chart.
 * Usage (in a theme): enqueue this script and add a canvas with id `sspa-public-chart-{post_id}`.
 *
 * The plugin registers rest routes that require authentication for most endpoints; adjust permission checks
 * if you want public read access.
 */
(function(){
	'use strict';
	if (typeof window.SSPA_Public === 'undefined') return;

	const postId = window.SSPA_Public.postId;
	const canvasId = 'sspa-public-chart-' + postId;
	const el = document.getElementById(canvasId);
	if (!el) return;

	const url = window.SSPA_Public.restUrl + '/aggregated?post_id=' + postId;
	fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': window.SSPA_Public.nonce } })
		.then(res => res.json())
		.then(data => {
			// data will be an array of rows by platform
			const labels = data.map(r => r.platform);
			const values = data.map(r => parseInt(r.total_shares, 10));
			new Chart(el.getContext('2d'), {
				type: 'pie',
				data: { labels: labels, datasets: [{ data: values }] }
			});
		}).catch(err => {
			console.error('SSPA public chart error', err);
		});
})();
