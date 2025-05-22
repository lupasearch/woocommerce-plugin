<?php
if (!defined('ABSPATH')) {
    exit;
}

class LupaSearch_Frontend {
    public function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    public function enqueue_scripts() {
        wp_enqueue_script(
            'lupasearch-client',
            'https://cdn.lupasearch.com/client/lupasearch-latest.min.js',
            array(),
            null,
            true
        );

        $ui_plugin_key = LupaSearch_Config::get_ui_plugin_key();
        if (!empty($ui_plugin_key)) {
            wp_add_inline_script('lupasearch-client', 
                'lupaSearch.init("' . esc_js($ui_plugin_key) . '", {});'
            );
        }
    }
}
