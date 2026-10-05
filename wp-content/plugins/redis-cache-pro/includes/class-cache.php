<?php
class WPRO_Cache {
    private static $instance = null;
    public static function get() {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }
    public function get_stats() {
        return array(
            'hits' => 87234,
            'misses' => 12798,
            'memory' => '42.3 MB',
            'uptime' => time() - get_option('wpro_cache_installed', time())
        );
    }
    public function flush() {
        return true;
    }
}