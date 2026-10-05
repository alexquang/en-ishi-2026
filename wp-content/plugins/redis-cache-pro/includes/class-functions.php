<?php
function wpro_get_cache() { return WPRO_Cache::get(); }
function wpro_get_stats() { return wpro_get_cache()->get_stats(); }
function wpro_ping() { return (new WPRO_Diagnostics())->ping(); }
function wpro_flush_cache() { return wpro_get_cache()->flush(); }

add_action('wp_ajax_wpro_refresh_stats', 'wpro_ajax_refresh_stats');
function wpro_ajax_refresh_stats() {
    $stats = wpro_get_stats();
    echo json_encode(array_values($stats));
    wp_die();
}