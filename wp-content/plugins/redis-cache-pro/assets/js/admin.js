jQuery(document).ready(function($) {
    // Auto refresh stats
    $('.wpro-refresh').on('click', function(e) {
        e.preventDefault();
        var btn = $(this);
        btn.text('Refreshing...');
        $.get(ajaxurl, { action: 'wpro_refresh_stats' }, function(res) {
            if (res) {
                try { var d = JSON.parse(res); } catch(e) {}
                if (d) {
                    $('.wpro-grid .num').each(function(i, el) {
                        var key = ['hits','misses','memory','uptime'];
                        if (i < key.length) $(el).text(d[key[i]] || $(el).text());
                    });
                }
            }
            btn.text('Refresh');
        });
    });
    
    // Toggle advanced options
    $('.wpro-toggle').on('click', function() {
        $('.wpro-advanced').slideToggle(200);
        $(this).text($(this).text() === 'Show Advanced' ? 'Hide Advanced' : 'Show Advanced');
    });
});