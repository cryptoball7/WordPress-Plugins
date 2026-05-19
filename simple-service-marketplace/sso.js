


jQuery(document).ready(function($) {
    $('#sso-order-form').on('submit', function(e) {
        e.preventDefault();

        var formData = new FormData(this);

        formData.append('action', 'sso_submit_order');

        $.ajax({
            url: sso_ajax.url,
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            success: function(response) {
                if (response.success) {
                    $('#sso-response').html(
                        '<p>Order submitted! <a href=\"' + response.data.link + '\">View your order</a></p>'
                    );
                } else {
                    $('#sso-response').html('<p>Error submitting order</p>');
                }
            },
            error: function() {
                $('#sso-response').html('<p>Server error</p>');
            }
        });

        $('#sso-message-form').on('submit', function(e) {
            e.preventDefault();
        
            $.post(sso_ajax.url, {
                action: 'sso_send_message',
                order_id: $('input[name="order_id"]').val(),
                message: $('textarea[name="message"]').val()
            }, function() {
                location.reload(); // later: replace with live append
            });
        });

    });
});