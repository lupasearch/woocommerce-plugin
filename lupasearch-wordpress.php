<?php
/**
 * Plugin Name: LupaSearch for WooCommerce
 * Description: LupaSearch integration for WooCommerce stores
 * Version: 1.0.0
 * Author: LupaSearch
 * Author URI: https://lupasearch.com
 * License: MIT
 */

if (!defined('ABSPATH')) {
    exit;
}

// Make sure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

define('LUPASEARCH_PLUGIN_DIR', plugin_dir_path(__FILE__));

require_once LUPASEARCH_PLUGIN_DIR . 'includes/class-lupasearch-config.php';
require_once LUPASEARCH_PLUGIN_DIR . 'includes/class-lupasearch-document-generator.php';
require_once LUPASEARCH_PLUGIN_DIR . 'includes/class-lupasearch-sync.php'; // Add this line
require_once LUPASEARCH_PLUGIN_DIR . 'includes/admin/class-lupasearch-admin.php';
require_once LUPASEARCH_PLUGIN_DIR . 'includes/class-lupasearch-frontend.php';
require_once LUPASEARCH_PLUGIN_DIR . 'includes/class-lupasearch-widget.php';
require_once LUPASEARCH_PLUGIN_DIR . 'class-lupasearch-product-provider.php';
require_once LUPASEARCH_PLUGIN_DIR . 'includes/class-lupasearch-blocks.php';

// Initialize plugin
function lupasearch_init() {
    new LupaSearch_Admin();
    new LupaSearch_Frontend();
    new LupaSearch_Blocks();
    
    // Register widget
    add_action('widgets_init', function() {
        register_widget('LupaSearch_Widget');
    });

    // Register shortcodes
    add_shortcode('lupa_search_block', 'lupasearch_search_shortcode');
    add_shortcode('lupa_search_results_block', 'lupasearch_results_shortcode');
}

function lupasearch_search_shortcode($atts) {
    return '<div><div id="searchBox"></div></div>';
}

function lupasearch_results_shortcode($atts) {
    return '<div><div id="searchResults"></div></div>';
}

add_action('plugins_loaded', 'lupasearch_init');