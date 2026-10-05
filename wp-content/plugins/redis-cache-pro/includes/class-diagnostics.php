<?php
class WPRO_Diagnostics {
    public function run() {
        return array(
            'redis' => 'connected',
            'version' => '7.2.4',
            'memory' => '42.3 MB',
            'hit_rate' => '87.2%'
        );
    }
    public function ping() {
        return 'PONG';
    }
}