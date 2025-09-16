(function($){
	'use strict';

	// Guard
	if ( typeof ASE === 'undefined' ) {
		window.ASE = {};
	}

	$(function(){
		// Click handler for reaction buttons
		$('.ase-activity-stream').on('click', '.ase-reaction-btn', function(e){
			e.preventDefault();
			var $btn = $(this);
			var $container = $btn.closest('.ase-reactions');
			var postId = $container.data('ase-post-id');
			var reaction = $btn.data('reaction');

			// Optimistic UI: toggle visual immediately
			var $countEl = $btn.find('.ase-count');
			var current = parseInt( $countEl.attr('data-ase-count') || '0', 10 );
			var newCount = current + 1;
			if ( $btn.hasClass('ase-reacted') ) {
				newCount = current - 1;
			}

			$btn.toggleClass('ase-reacted');
			$countEl.attr('data-ase-count', newCount).text(newCount);

			// Send AJAX request
			$.post( ASE.ajax_url, {
				action: 'ase_toggle_reaction',
				nonce: ASE.nonce,
				post_id: postId,
				reaction: reaction
			}, function( res ){
				if ( res && res.success ) {
					// Update counts from authoritative response (in case of race)
					var counts = res.data.counts || {};
					$container.find('.ase-reaction-btn').each(function(){
						var $b = $(this);
						var key = $b.data('reaction');
						if ( counts.hasOwnProperty(key) ) {
							$b.find('.ase-count').attr('data-ase-count', counts[key]).text(counts[key]);
						}
					});

					// Optionally add tooltip content
					// For now, we won't fetch user lists via AJAX to keep it simple.
				} else {
					// Revert optimistic UI on failure
					$btn.toggleClass('ase-reacted');
					$countEl.attr('data-ase-count', current).text(current);
					if ( res && res.data && res.data.message ) {
						alert(res.data.message);
					} else {
						alert('Could not save reaction.');
					}
				}
			}).fail(function(){
				// Revert on network error
				$btn.toggleClass('ase-reacted');
				$countEl.attr('data-ase-count', current).text(current);
				alert('Request failed. Please try again.');
			});
		});

		// Hover tooltip: show a simple message listing counts
		$('.ase-activity-stream').on('mouseenter', '.ase-reaction-btn', function(){
			var $btn = $(this);
			var $container = $btn.closest('.ase-activity');
			var $tooltip = $container.find('.ase-tooltip');
			var reaction = $btn.data('reaction');
			var label = ASE.reactions[reaction] ? ASE.reactions[reaction]['label'] : reaction;
			var count = $btn.find('.ase-count').attr('data-ase-count') || '0';
			$tooltip.text(label + ': ' + count).attr('aria-hidden', 'false').show();
		});
		$('.ase-activity-stream').on('mouseleave', '.ase-reaction-btn', function(){
			var $container = $(this).closest('.ase-activity');
			var $tooltip = $container.find('.ase-tooltip');
			$tooltip.hide().attr('aria-hidden', 'true');
		});

		// On load, mark buttons the user already reacted to (best-effort: we don't have user lists available in the initial markup)
		// Improvement: server can output per-user reacted flags if user is logged in. For anonymous we used a cookie; large installations might require dedicated table.
	});
})(jQuery);
