(function($){
	'use strict';

	// Utility: fetch aggregated
	function fetchAggregated(dateFrom, dateTo) {
		const url = new URL(SSPA_Admin.rest_url + '/aggregated');
		if (dateFrom) url.searchParams.set('date_from', dateFrom);
		if (dateTo) url.searchParams.set('date_to', dateTo);
		url.searchParams.set('limit', 100);

		return fetch(url.toString(), {
			method: 'GET',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': SSPA_Admin.nonce,
			}
		}).then(function(res){ return res.json(); });
	}

	// Build chart
	let chart = null;
	function renderChart(data) {
		const ctx = document.getElementById('sspa-chart').getContext('2d');

		// Aggregate per-post totals across platforms for chart labels (top N)
		const labels = data.map(function(row){ return row.post_title || ('Post ' + row.post_id); }).slice(0, 10);
		const values = data.map(function(row){ return parseInt(row.total_shares, 10); }).slice(0, 10);

		if (chart) chart.destroy();

		chart = new Chart(ctx, {
			type: 'bar',
			data: {
				labels: labels,
				datasets: [{
					label: 'Total Shares',
					data: values,
					backgroundColor: undefined // let Chart.js default palette handle it
				}]
			},
			options: {
				responsive: true,
				plugins: {
					legend: { display: false },
					tooltip: { mode: 'index', intersect: false }
				},
				scales: {
					x: { ticks: { maxRotation: 45, minRotation: 0 } },
					y: { beginAtZero: true }
				}
			}
		});
	}

	// Render table
	function renderTable(data) {
		const $tbody = $('#sspa-table tbody');
		$tbody.empty();
		data.forEach(function(row){
			const postLink = '<a href="' + location.origin + '/?p=' + row.post_id + '" target="_blank" rel="noopener">' + (row.post_title || ('Post ' + row.post_id)) + '</a>';
			const tr = '<tr><td>' + postLink + '</td><td>' + row.platform + '</td><td>' + row.total_shares + '</td></tr>';
			$tbody.append(tr);
		});
	}

	// Initialize: load current aggregated
	function loadData() {
		const dateFrom = $('#sspa-date-from').val() || null;
		const dateTo = $('#sspa-date-to').val() || null;

		fetchAggregated(dateFrom, dateTo).then(function(json){
			if (Array.isArray(json)) {
				renderChart(json);
				renderTable(json);
			}
		}).catch(function(err){
			console.error('SSPA fetch error', err);
		});
	}

	$(document).ready(function(){
		// Initial load
		loadData();

		$('#sspa-refresh').on('click', function(e){
			e.preventDefault();
			loadData();
		});

		// CSV import via form -> admin-ajax
		$('#sspa-import-form').on('submit', function(e){
			e.preventDefault();
			const form = this;
			const fd = new FormData(form);
			// Use admin-ajax endpoint defined by plugin
			fd.append('action', 'sspa_import_csv');

			$('#sspa-import-status').text('Importing...');
			fetch(ajaxurl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': SSPA_Admin.nonce },
				body: fd
			}).then(function(res){
				return res.json();
			}).then(function(json){
				if (json.success) {
					$('#sspa-import-status').text(SSPA_Admin.strings.import_success + ' (' + json.data.inserted + ')');
					loadData();
				} else {
					const err = json.data ? (json.data.message || JSON.stringify(json.data)) : json.message || 'Unknown';
					$('#sspa-import-status').text(SSPA_Admin.strings.import_error + ' — ' + err);
				}
			}).catch(function(err){
				$('#sspa-import-status').text(SSPA_Admin.strings.import_error);
				console.error(err);
			});
		});

		// Export via REST
		$('#sspa-export').on('click', function(e){
			e.preventDefault();
			const dateFrom = $('#sspa-date-from').val() || null;
			const dateTo = $('#sspa-date-to').val() || null;
			let url = SSPA_Admin.rest_url + '/export';
			const params = new URLSearchParams();
			if (dateFrom) params.append('date_from', dateFrom);
			if (dateTo) params.append('date_to', dateTo);
			if (params.toString()) url += '?' + params.toString();

			// redirect to the CSV endpoint to download
			window.open(url, '_blank');
		});
	});
})(jQuery);
