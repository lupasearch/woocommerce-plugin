<?php
if (!defined('ABSPATH')) {
    exit;
}

class LupaSearch_Frontend {
    public function __construct() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_filter('template_include', array($this, 'override_search_template'));
        add_action('pre_get_posts', array($this, 'neutralize_wp_search_query_if_overridden'));
        add_action('wp', array($this, 'lupasearch_maybe_override_404_search'));
        add_filter('request', array($this, 'lupasearch_filter_request_query_vars'));
    }

    public function lupasearch_filter_request_query_vars($query_vars) {
        $s_param_exists = isset($_GET['s']); // Check existence first (related to warning on line 19)
        $is_lupa_search_page = (isset($query_vars['pagename']) && $query_vars['pagename'] === 'search') ||
                               (isset($query_vars['page_id']) && $query_vars['page_id'] == get_page_by_path('search')->ID);
        $lupa_override_active = get_option('lupasearch_override_wp_search', true);

        if ($is_lupa_search_page && $s_param_exists && $lupa_override_active) {
            // This is the block where $_GET['s'] (its existence) leads to an action.
            // Perform nonce check here.
            $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])) : '';
            if (!wp_verify_nonce($nonce, 'lupasearch_search_action')) {
                // Nonce invalid or missing, do not apply LupaSearch modification.
                return $query_vars;
            }
            // If LupaSearch is overriding, we don't want WP to process 's' for this page load.
            // LupaSearch JS will pick 's' from the URL.
            unset($query_vars['s']);
        }
        return $query_vars;
    }

    public function lupasearch_maybe_override_404_search() {
        if (is_404()) {
            // Ensure LupaSearch scripts are enqueued
            $this->enqueue_scripts();

            // Attempt to replace the search form if get_search_form() is used by the theme
            add_filter('get_search_form', array($this, 'lupasearch_custom_search_form_for_404'), 20);

            // Prevent WC_Widget_Product_Search from displaying on 404 pages
            if (class_exists('WC_Widget_Product_Search')) {
                add_filter('widget_display_callback', array($this, 'lupasearch_filter_widget_display_on_404'), 10, 3);
            }
        }
    }

    public function lupasearch_custom_search_form_for_404($form) {
        // Return the LupaSearch box HTML, which lupasearch-init.js will target.
        // This replaces the output of get_search_form().
        // The LupaSearch_Widget essentially outputs this for the search box part.
        return '<div><div id="searchBox"></div></div>';
    }

    public function lupasearch_filter_widget_display_on_404($instance, $widget_object, $args) {
        // Check if the current widget being processed is an instance of WC_Widget_Product_Search
        if (is_a($widget_object, 'WC_Widget_Product_Search')) {
            // If it is, and we are on a 404 page (checked by the caller hook setup),
            // return false to prevent this widget from being displayed.
            return false;
        }
        // For any other widget, return the original instance to let it display.
        return $instance;
    }

    public function neutralize_wp_search_query_if_overridden($query) {
        // Check if it's the main query, on the frontend, is a search, and our override is active.
        if ($query->is_main_query() && !is_admin() && $query->is_search() && get_option('lupasearch_override_wp_search', true)) {
            // If we are in this block due to $_GET['s'] (which makes $query->is_search() true),
            // or if $_GET['post_type'] is present, we are processing "form data".
            // These accesses relate to warnings on lines 77 and 78.
            if (isset($_GET['s']) || isset($_GET['post_type'])) {
                $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])) : '';
                if (!wp_verify_nonce($nonce, 'lupasearch_search_action')) {
                    // Nonce failed, LupaSearch doesn't modify the query. WordPress will proceed normally.
                    return;
                }
            }

            // Re-define these after nonce check for clarity, or use them if passed from a broader scope.
            $s_param_present = isset($_GET['s']);
            $post_type_param_present = isset($_GET['post_type']);
            
            // Clear the search term from WordPress's query.
            $query->set('s', ''); 

            if ($post_type_param_present) {
                 $query->set('post_type', 'page');
                 $query->is_post_type_archive = false;
                 $query->is_archive = false;
            }

            if (is_page('search') && $s_param_present) {
                $query->set('page_id', ''); 
                $query->set('pagename', '');
            }

            $query->is_search = true;
            $query->is_404    = false; 
            
            if (empty($query->posts)) {
                $query->found_posts = 1; 
                $query->post_count = 1; 
                $query->max_num_pages = 1; 
            }
        }
    }

    public function override_search_template($template) {
        if (is_search() && get_option('lupasearch_override_wp_search', true) && !is_admin()) {
            $new_template = plugin_dir_path(__FILE__) . '../templates/lupasearch-search-page.php';
            if (file_exists($new_template)) {
                // Ensure scripts are enqueued specifically for this template
                $this->enqueue_scripts(); 
                return $new_template;
            }
        }
        return $template;
    }

    public function enqueue_scripts() {
        // Only enqueue if not already enqueued to avoid duplication
        if (!wp_script_is('lupasearch-client', 'enqueued')) {
            wp_enqueue_script(
                'lupasearch-client',
                'https://cdn.lupasearch.com/client/lupasearch-latest.min.js',
                array(),
                'latest', // Version for CDN script
                true
            );
        }

        if (!wp_script_is('lupasearch-init', 'enqueued')) {
            wp_enqueue_script(
                'lupasearch-init',
                plugin_dir_url(__FILE__) . '../js/lupasearch-init.js', // Assuming js folder is one level up from includes
                array('lupasearch-client'), // Make sure lupasearch-client is loaded first
                '1.0.0', // Version for local script
                true
            );
        }
        
        $params_to_localize = array(
            'search_nonce' => wp_create_nonce('lupasearch_search_action')
        );

        $ui_plugin_key = LupaSearch_Config::get_ui_plugin_key();
        if (!empty($ui_plugin_key)) {
            $params_to_localize['ui_plugin_key'] = $ui_plugin_key;
        }
        
        if (wp_script_is('lupasearch-init', 'enqueued')) {
            global $wp_scripts;
            // Ensure data is attached only once to prevent issues if hook is called multiple times.
            if (empty($wp_scripts->get_data('lupasearch-init', 'data'))) {
                wp_localize_script('lupasearch-init', 'lupaSearchInitParams', $params_to_localize);
            }
        }
    }
}
