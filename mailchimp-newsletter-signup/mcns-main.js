(function($){
    $(document).ready(function(){
        var $form = $('#mcns-form');
        if ( !$form.length ) return;

        $form.on('submit', function(e){
            e.preventDefault();
            var $msg = $('#mcns-messages').empty();
            var $btn = $('#mcns-submit');

            $btn.prop('disabled', true).text('Sending...');

            var data = {
                action: mcns_ajax.action,
                nonce: $('input[name="nonce"]', $form).val(),
                email: $('#mcns_email', $form).val(),
                fname: $('#mcns_fname', $form).val(),
                lname: $('#mcns_lname', $form).val()
            };

            $.post(mcns_ajax.ajax_url, data)
                .done(function(resp){
                    if ( resp.success ) {
                        $msg.html('<div class="mcns-success">'+ resp.data.message +'</div>');
                        $form[0].reset();
                    } else {
                        var message = (resp.data && resp.data.message) ? resp.data.message : 'An error occurred.';
                        $msg.html('<div class="mcns-error">'+ message +'</div>');
                    }
                })
                .fail(function(jqXHR){
                    var text = 'An unexpected error occurred.';
                    if ( jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message ) {
                        text = jqXHR.responseJSON.data.message;
                    } else if ( jqXHR && jqXHR.statusText ) {
                        text = jqXHR.statusText;
                    }
                    $msg.html('<div class="mcns-error">'+ text +'</div>');
                })
                .always(function(){
                    $btn.prop('disabled', false).text( $btn.data('default') || 'Subscribe' );
                });

        });

        // store default button text
        $('#mcns-submit').each(function(){
            var $b = $(this);
            if (!$b.data('default')) $b.data('default', $b.text());
        });
    });
})(jQuery);
