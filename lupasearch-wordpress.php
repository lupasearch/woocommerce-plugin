<?php
/**
 * Plugin Name: LupaSearch for WooCommerce
 * Description: LupaSearch integration for WooCommerce stores
 * Version: 1.0.0
 * Author: LupaSearch
 * Author URI: https://lupasearch.com
 * License: MIT
 * Text Domain: lupasearch
 * Domain Path: /languages
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
    // Load plugin textdomain
    load_plugin_textdomain('lupasearch', false, dirname(plugin_basename(__FILE__)) . '/languages/');

    new LupaSearch_Admin();
    new LupaSearch_Frontend();
    new LupaSearch_Blocks();
    new LupaSearch_Sync(); // Ensure Sync class is instantiated for table creation etc.
    
    // Define Cron Hook Names
    if (!defined('LUPASEARCH_CRON_SYNC_PRODUCT')) {
        define('LUPASEARCH_CRON_SYNC_PRODUCT', 'lupasearch_sync_single_product_event');
    }
    if (!defined('LUPASEARCH_CRON_DELETE_PRODUCT')) {
        define('LUPASEARCH_CRON_DELETE_PRODUCT', 'lupasearch_delete_single_product_event');
    }

    // Hook Cron Actions to Static Handlers in LupaSearch_Sync
    // Ensure LupaSearch_Sync class is loaded before these lines if not already guaranteed
    add_action(LUPASEARCH_CRON_SYNC_PRODUCT, array('LupaSearch_Sync', 'handle_sync_product_cron_event'), 10, 1);
    add_action(LUPASEARCH_CRON_DELETE_PRODUCT, array('LupaSearch_Sync', 'handle_delete_product_cron_event'), 10, 1);

    // WooCommerce Action Hooks for Scheduling Cron Jobs
    add_action('save_post_product', 'lupasearch_schedule_sync_on_product_save', 10, 3);
    add_action('woocommerce_update_product_stock', 'lupasearch_schedule_sync_on_stock_change', 10, 1);
    // For variations, stock is often managed at the variation level.
    // woocommerce_save_product_variation hook might be better for specific variation stock changes.
    // However, woocommerce_update_product_stock should cover most cases if parent product stock is affected or managed.
    add_action('wp_trash_post', 'lupasearch_schedule_delete_on_trash');
    add_action('before_delete_post', 'lupasearch_schedule_delete_on_permanent_delete', 10, 1);
    
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

// Callback functions for scheduling cron jobs

function lupasearch_is_auto_sync_enabled() {
    return (bool) get_option('lupasearch_auto_sync', false);
}

function lupasearch_schedule_sync_on_product_save($post_id, $post, $update) {
    if (!lupasearch_is_auto_sync_enabled() || $post->post_type !== 'product' || $post->post_status !== 'publish') {
        return;
    }
    // For new products ($update is false) or updated products.
    // wp_is_post_revision check might be useful if revisions trigger save_post too often.
    if (wp_is_post_revision($post_id)) {
        return;
    }

    if (!wp_next_scheduled(LUPASEARCH_CRON_SYNC_PRODUCT, array('product_id' => $post_id))) {
        wp_schedule_single_event(time() + 5, LUPASEARCH_CRON_SYNC_PRODUCT, array('product_id' => $post_id)); // Add a small delay
    }
}

function lupasearch_schedule_sync_on_stock_change($product_or_variation_id) {
    if (!lupasearch_is_auto_sync_enabled()) {
        return;
    }
    
    $product_id = $product_or_variation_id;
    // If it's a variation, get the parent product ID for consistency if LupaSearch indexes parent products
    $product = wc_get_product($product_or_variation_id);
    if ($product && $product->is_type('variation')) {
        $product_id = $product->get_parent_id();
    }
    
    // Ensure it's a valid product and is published
    $parent_product = wc_get_product($product_id);
    if (!$parent_product || $parent_product->get_status() !== 'publish') {
        return;
    }

    if (!wp_next_scheduled(LUPASEARCH_CRON_SYNC_PRODUCT, array('product_id' => $product_id))) {
        wp_schedule_single_event(time() + 5, LUPASEARCH_CRON_SYNC_PRODUCT, array('product_id' => $product_id));
    }
}

function lupasearch_schedule_delete_on_trash($post_id) {
    if (!lupasearch_is_auto_sync_enabled() || get_post_type($post_id) !== 'product') {
        return;
    }
    if (!wp_next_scheduled(LUPASEARCH_CRON_DELETE_PRODUCT, array('product_id' => $post_id))) {
        wp_schedule_single_event(time() + 5, LUPASEARCH_CRON_DELETE_PRODUCT, array('product_id' => $post_id));
    }
}

function lupasearch_schedule_delete_on_permanent_delete($post_id) {
    // This hook runs before the post is deleted from the database.
    if (!lupasearch_is_auto_sync_enabled() || get_post_type($post_id) !== 'product') {
        return;
    }
    // Check if a sync job is scheduled for this product and unschedule it, as delete takes precedence.
    $timestamp_sync = wp_next_scheduled(LUPASEARCH_CRON_SYNC_PRODUCT, array('product_id' => $post_id));
    if ($timestamp_sync) {
        wp_unschedule_event($timestamp_sync, LUPASEARCH_CRON_SYNC_PRODUCT, array('product_id' => $post_id));
    }

    if (!wp_next_scheduled(LUPASEARCH_CRON_DELETE_PRODUCT, array('product_id' => $post_id))) {
        wp_schedule_single_event(time() + 5, LUPASEARCH_CRON_DELETE_PRODUCT, array('product_id' => $post_id));
    }
}

add_action('plugins_loaded', 'lupasearch_init');

// Plugin activation/deactivation hooks
register_activation_hook(__FILE__, 'lupasearch_activate');
register_deactivation_hook(__FILE__, 'lupasearch_deactivate');

function lupasearch_activate() {
    // Actions on activation can be added here if needed in the future
    // For example, ensuring the log table exists (though LupaSearch_Sync constructor does this)
    // Or scheduling a recurring "health check" cron if desired.
    // For now, main cron events are scheduled on-demand by product actions.
}

function lupasearch_deactivate() {
    // Clear scheduled cron jobs
    // For single events, we need to find them if args are dynamic (like product_id)
    // A more robust way is to iterate through all cron jobs and unschedule those matching our hooks.
    // However, wp_clear_scheduled_hook is simpler if we don't have too many variations.
    // For now, we'll clear the base hooks. If specific product_id args are an issue,
    // a more complex cleanup might be needed, or rely on WP-Cron to eventually discard them.

    // Define constants here as well if not already defined globally or ensure they are accessible
    if (!defined('LUPASEARCH_CRON_SYNC_PRODUCT')) {
        define('LUPASEARCH_CRON_SYNC_PRODUCT', 'lupasearch_sync_single_product_event');
    }
    if (!defined('LUPASEARCH_CRON_DELETE_PRODUCT')) {
        define('LUPASEARCH_CRON_DELETE_PRODUCT', 'lupasearch_delete_single_product_event');
    }
    
    // Get all scheduled cron jobs
    $crons = _get_cron_array();
    if (!empty($crons)) {
        foreach ($crons as $timestamp => $cron) {
            if (isset($cron[LUPASEARCH_CRON_SYNC_PRODUCT])) {
                foreach ($cron[LUPASEARCH_CRON_SYNC_PRODUCT] as $hook_id => $details) {
                    wp_unschedule_event($timestamp, LUPASEARCH_CRON_SYNC_PRODUCT, $details['args']);
                }
            }
            if (isset($cron[LUPASEARCH_CRON_DELETE_PRODUCT])) {
                 foreach ($cron[LUPASEARCH_CRON_DELETE_PRODUCT] as $hook_id => $details) {
                    wp_unschedule_event($timestamp, LUPASEARCH_CRON_DELETE_PRODUCT, $details['args']);
                }
            }
        }
    }
}
