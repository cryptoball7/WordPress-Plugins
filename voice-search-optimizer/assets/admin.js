jQuery(document).ready(function($){
    $('#vso-run-scan').on('click', function(e){
        e.preventDefault();
        var $btn = $(this);
        $btn.prop('disabled', true);
        $('#vso-scan-spinner').show();
        $.post(vso_ajax.ajax_url, { action: 'vso_run_scan', nonce: vso_ajax.nonce }, function(resp){
            $btn.prop('disabled', false);
            $('#vso-scan-spinner').hide();
            if (resp.success) {
                renderReport(resp.data);
            } else {
                $('#vso-results').html('<div class="notice notice-error"><p>'+resp.data.message+'</p></div>');
            }
        });
    });

    function renderReport(data) {
        var html = '';
        html += '<h2>Summary Score: '+data.score+'/100</h2>';
        html += '<table class="widefat striped"><tbody>';
        html += '<tr><th>HTTPS</th><td>' + (data.https ? 'Yes' : 'No') + '</td></tr>';
        html += '<tr><th>Mobile responsive (viewport meta)</th><td>' + (data.mobile_responsive ? 'Yes' : 'No') + '</td></tr>';
        html += '<tr><th>Sitemap</th><td>' + (data.sitemap ? '<a href="'+data.sitemap+'" target="_blank">Found</a>' : 'Not found') + '</td></tr>';
        html += '<tr><th>robots.txt</th><td>' + (data.robots ? 'Present' : 'Not found') + '</td></tr>';
        html += '</tbody></table>';

        // content checks
        html += '<h3>Content sample checks (showing up to 20 items)</h3>';
        html += '<table class="widefat striped"><thead><tr><th>Post</th><th>Q Headings</th><th>Q Sentences (first 300 words)</th><th>Meta</th><th>Avg sentence length</th><th>Score</th></tr></thead><tbody>';
        data.content_checks.forEach(function(item){
            html += '<tr>';
            html += '<td><a href="/wp-admin/post.php?post='+item.post_id+'&action=edit">'+escapeHtml(item.title)+'</a></td>';
            html += '<td>'+item.question_headings+'</td>';
            html += '<td>'+item.questions_in_content+'</td>';
            html += '<td>'+(item.meta_description ? 'Yes' : 'No')+'</td>';
            html += '<td>'+item.avg_sentence_length+'</td>';
            html += '<td>'+item.score+'</td>';
            html += '</tr>';
        });
        html += '</tbody></table>';

        // structured data
        html += '<h3>Structured data (sample)</h3>';
        html += '<ul>';
        html += '<li>JSON-LD found on sample posts: '+data.structured_data.json_ld_present+' / '+data.structured_data.count+'</li>';
        html += '<li>FAQ schema instances: '+data.structured_data.faq_schema_present+'</li>';
        html += '<li>QAPage schema instances: '+data.structured_data.qapage_present+'</li>';
        html += '</ul>';

        // images
        html += '<h3>Images</h3>';
        html += '<p>Checked recent '+data.images.checked_posts+' posts: '+data.images.total_images+' images found, '+data.images.images_with_alt+' with alt attributes ('+data.images.percent_with_alt+'%)</p>';

        html += '<h3>Recommendations</h3>';
        html += '<ol>';
        if (!data.https) html += '<li>Enable HTTPS (install an SSL and ensure site runs on https).</li>';
        if (!data.mobile_responsive) html += '<li>Ensure your theme outputs a viewport meta tag or uses a responsive layout.</li>';
        if (!data.sitemap) html += '<li>Install an SEO plugin (Yoast, RankMath) or generate a sitemap and make it discoverable at /sitemap.xml.</li>';
        if (!data.structured_data.json_ld_present) html += '<li>Add structured data (FAQPage or QAPage JSON-LD) to question-and-answer style posts.</li>';
        if (data.images.percent_with_alt < 90) html += '<li>Add meaningful alt attributes to images (especially images used in how-to or FAQ content).</li>';
        html += '<li>Focus on short, direct answer snippets. Use question headings followed by concise answers (20 words or less) where possible.</li>';
        html += '</ol>';

        $('#vso-results').html(html);
    }

    function escapeHtml(text) {
        return text.replace(/["&'<>]/g, function (a) { return {'"':'&quot;','&':'&amp;',"'":"&#39;",'<':'&lt;','>':'&gt;'}[a]; });
    }
});
