jQuery(document).ready(function($) {
    function revealContent(container) {
        container.find('.ccr-lock').hide();
        container.find('.ccr-hidden-content').fadeIn();
    }

    $('.ccr-unlock-btn').on('click', function() {
        revealContent($(this).closest('.ccr-container'));
    });

    $('.ccr-share-btn').on('click', function() {
        alert('Pretend to share... content unlocked!');
        revealContent($(this).closest('.ccr-container'));
    });

    $('.ccr-subscribe-btn').on('click', function() {
        const email = $(this).siblings('.ccr-email').val();
        if (email.length > 5 && email.includes('@')) {
            alert('Subscribed! Content unlocked.');
            revealContent($(this).closest('.ccr-container'));
        } else {
            alert('Please enter a valid email.');
        }
    });
});
