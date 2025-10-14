(function($){
    'use strict';

    // Helper: track impression/conversion
    function track(action, experimentId, variantIdx) {
        $.post(aiab_frontend.ajax_url, {
            action: 'aiab_track',
            nonce: aiab_frontend.nonce,
            track_action: action,
            experiment_id: experimentId,
            variant_idx: variantIdx
        }, function(resp){
            // console.debug('aiab track', action, resp);
        }, 'json');
    }

    // Public: allow theme JS to trigger conversion
    window.aiabTrackConversion = function(experimentId) {
        $('.aiab-experiment[data-experiment-id="'+experimentId+'"]').each(function(){
            var $t = $(this);
            var exp = parseInt($t.data('experiment-id'), 10);
            var idx = parseInt($t.data('variant-idx'), 10);
            track('conversion', exp, idx);
        });
    };

    $(document).ready(function(){
        $('.aiab-experiment').each(function(){
            var $wrapper = $(this);
            var experimentId = parseInt($wrapper.data('experiment-id'), 10);
            var variantIdx = parseInt($wrapper.data('variant-idx'), 10);
            var selector = $wrapper.data('selector') || '';

            var expData = {
                experimentId: experimentId,
                variantIdx: variantIdx
            };

            // We need to fetch the experiment's variant data from server via AJAX to know content
            // For simplicity, we embed it server-side when printing the wrapper. But since we didn't,
            // we'll request it via admin-ajax for read-only variant payload.
            $.post(aiab_frontend.ajax_url, {
                action: 'aiab_get_variants', // this AJAX handler will be added below
                nonce: aiab_frontend.nonce,
                experiment_id: experimentId
            }, function(resp){
                if ( resp && resp.success && resp.data.variants ) {
                    var variants = resp.data.variants;
                    var variant = variants[variantIdx] || variants[0];

                    if ( selector ) {
                        var $target = $(selector).first();
                        if ( $target.length ) {
                            if ( variant.type === 'title' || variant.type === 'cta' ) {
                                $target.text(variant.content);
                            } else if ( variant.type === 'layout' ) {
                                // simple layout injection: content may contain HTML/CSS
                                $target.html(variant.content);
                            } else {
                                $target.text(variant.content);
                            }
                            // Impression recorded
                            track('impression', experimentId, variantIdx);

                            // if goal is a selector clicks, we will attach handler in separate request (server provides goal)
                            if ( resp.data.goal_selector ) {
                                var $goal = $(resp.data.goal_selector).first();
                                if ( $goal.length ) {
                                    $goal.on('click', function(){
                                        track('conversion', experimentId, variantIdx);
                                    });
                                }
                            }
                        } else {
                            // If selector not found, place content inside the wrapper as fallback
                            $wrapper.html('<div class="aiab-inline-variant">'+variant.content+'</div>');
                            track('impression', experimentId, variantIdx);
                        }
                    } else {
                        // No selector: render inside wrapper
                        $wrapper.html('<div class="aiab-inline-variant">'+variant.content+'</div>');
                        track('impression', experimentId, variantIdx);
                    }
                }
            }, 'json');
        });
    });

})(jQuery);
