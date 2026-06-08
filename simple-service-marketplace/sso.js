


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

    });

$('#sso-message-form').on('submit', function(e) {
    e.preventDefault();

    const form = $(this);

    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: form.serialize(),
        success: function(response) {
            console.log('Submitted');

            // Clear textarea
            form.find('textarea[name="message"]').val('');

            // Later: append message to DOM
        }
    });
});


});