(function(window, document, jQuery){
	'use strict';
	if ( typeof crt_settings === 'undefined' ) return;

	var postId = crt_settings.post_id;

	// Send a view ping once per page load (debounced server-side)
	if ( postId ) {
		fetch( crt_settings.rest_url + 'view', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': crt_settings.nonce
			},
			body: JSON.stringify({ post_id: postId })
		}).catch(function(){ /* fail silently */ });
	}

	// Helper function for conversions; developer can call window.crt_record_conversion(...)
	window.crt_record_conversion = function( data ){
		// data: { post_id: optional, leads: int, revenue: float }
		data = data || {};
		fetch( crt_settings.rest_url + 'conversion', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': crt_settings.nonce
			},
			body: JSON.stringify(data)
		}).catch(function(){ /* fail silently */ });
	};

	// Example: if you want to auto-track form submissions, add handler:
	// document.addEventListener('submit', function(e){ if (e.target.matches('form.crt-convert')) { e.preventDefault(); window.crt_record_conversion({ post_id: postId, leads: 1 }); /* then submit form via ajax or normal submit */ }});
})(window, document, jQuery);
