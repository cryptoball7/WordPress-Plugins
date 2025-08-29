(function($){
    function updateButton($wrap, count, liked){
        $wrap.attr('data-liked', liked ? '1' : '0');
        $wrap.find('.fe-like-count').text(count);
        $wrap.find('.fe-like-label').text(liked ? (FELikeData.texts.liked || 'Liked') : (FELikeData.texts.like || 'Like'));
        $wrap.find('.fe-like-btn').attr('aria-pressed', liked ? 'true' : 'false');
        if(liked){ $wrap.addClass('fe-liked'); }
    }

    function setCookie(name, value, days) {
        var expires = "";
        if (days) {
            var date = new Date();
            date.setTime(date.getTime() + (days*24*60*60*1000));
            expires = "; expires=" + date.toUTCString();
        }
        document.cookie = name + "=" + (value || "")  + expires + "; path=/";
    }

    $(document).on('click', '.fe-like-wrap .fe-like-btn', function(e){
        e.preventDefault();
        var $wrap = $(this).closest('.fe-like-wrap');
        var postId = parseInt($wrap.attr('data-post-id'), 10);

        if($wrap.attr('data-liked') === '1'){ return; }

        $.ajax({
            url: FELikeData.ajax_url,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'fe_like',
                nonce: FELikeData.nonce,
                post_id: postId
            }
        }).done(function(res){
            if(res && res.success && res.data && typeof res.data.count !== 'undefined'){
                updateButton($wrap, res.data.count, true);
                setCookie('fe_liked_' + postId, '1', 365);
            } else {
                $wrap.find('.fe-like-feedback').text('Error: try again.');
            }
        }).fail(function(xhr){
            if(xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message === 'already_liked'){
                // treat as liked; just reflect UI
                var current = parseInt($wrap.find('.fe-like-count').text(), 10);
                updateButton($wrap, current, true);
                setCookie('fe_liked_' + postId, '1', 365);
            } else {
                $wrap.find('.fe-like-feedback').text('Error: try again.');
            }
        });
    });

    // On initial render, FELikeData may already have count + liked
    $(function(){
        $('.fe-like-wrap').each(function(){
            var $wrap = $(this);
            if(typeof FELikeData === 'object' && FELikeData.post_id == $wrap.attr('data-post-id')){
                updateButton($wrap, FELikeData.count || 0, FELikeData.has_liked ? true : false);
            }
        });
    });
})(jQuery);
