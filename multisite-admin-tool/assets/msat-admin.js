(function($){
 $(function(){
   $('#msat-search-button').on('click', function(e){
     e.preventDefault();
     var q = $('#msat-q').val();
     var post_type = $('#msat-post-type').val();
     var sites = $('#msat-sites').val();
     var url = msatParams.restRoot + 'search/posts';
     $.get(url, { q: q, post_type: post_type, sites: sites }, function(data){
         var html = '<ul>';
         if (!data.results.length) html += '<li>No results</li>';
         data.results.forEach(function(r){
            html += '<li>' + r.post_title + ' — <a href="'+ r.permalink + '" target="_blank">view</a> (site:'+r.site_id+')</li>';
         });
         html += '</ul>';
         $('#msat-results').html(html);
     });
   });

   $('#msat-user-search').on('click', function(e){
     e.preventDefault();
     var q = $('#msat-user-q').val();
     var url = msatParams.restRoot + 'search/users';
     $.get(url, { q: q }, function(data){
         var html = '<ul>';
         if (!data.results.length) html += '<li>No users found</li>';
         data.results.forEach(function(u){
           html += '<li>' + u.display_name + ' ('+ u.user_email +') - sites: ' + u.sites.map(function(s){return s.blog_id;}).join(',') + '</li>';
         });
         html += '</ul>';
         $('#msat-user-results').html(html);
     });
   });
 });
})(jQuery);