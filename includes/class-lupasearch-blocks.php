<?php
if (!defined('ABSPATH')) {
    exit;
}

class LupaSearch_Blocks {
    public function __construct() {
        add_action('init', array($this, 'register_blocks'));
    }

    public function register_blocks() {
        // Register the block script
        wp_register_script(
            'lupasearch-blocks-editor',
            plugins_url('/blocks/build/index.js', dirname(__FILE__)),
            array('wp-blocks', 'wp-element', 'wp-editor', 'wp-components')
        );

        // Register each block
        register_block_type('lupasearch/search-box', array(
            'editor_script' => 'lupasearch-blocks-editor',
            'render_callback' => array($this, 'render_search_box')
        ));

        register_block_type('lupasearch/search-results', array(
            'editor_script' => 'lupasearch-blocks-editor',
            'render_callback' => array($this, 'render_search_results')
        ));
    }

    public function render_search_box() {
        return '<div><div id="searchBox"></div></div>';
    }

    public function render_search_results() {
        return '<div><div id="searchResults"></div></div>';
    }
}
