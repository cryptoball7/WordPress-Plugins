jQuery(function($){
    $('#aiab-generate').on('click', function(e){
        e.preventDefault();
        var post_id = $('#post_ID').val();
        var hint = prompt('Optional hint for the AI (describe your page or the target element). Leave blank to use defaults.');
        var $btn = $(this).prop('disabled', true).text('Generating…');

        $.post(aiab_admin.ajax_url, {
            action: 'aiab_generate_variants',
            nonce: aiab_admin.nonce,
            post_id: post_id,
            hint: hint
        }, function(resp){
            $btn.prop('disabled', false).text('Auto-generate variants (AI)');
            if ( resp && resp.success && resp.data.variants ) {
                var v = resp.data.variants;
                var json = JSON.stringify(v, null, 2);
                $('textarea[name="aiab_variants"]').val(json);
                alert('Variants generated — review and save the experiment.');
            } else {
                alert('AI generation failed: ' + (resp.data || 'unknown error'));
            }
        }, 'json').fail(function(xhr){
            $btn.prop('disabled', false).text('Auto-generate variants (AI)');
            alert('Request failed: ' + xhr.responseText);
        });
    });
});
