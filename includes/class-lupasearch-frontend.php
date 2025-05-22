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
            
            // Clear the search term from WordPress's query.
            // Our LupaSearch JS will get the search term directly from the URL.
            $query->set('s', ''); 

            // Optionally, to further prevent interference from WooCommerce or other CPT archives,
            // especially if `post_type=product` is in the URL.
            // This tells WordPress not to treat it as a typical post type archive search.
            if (isset($_GET['post_type'])) {
                 $query->set('post_type', 'page'); // Or any non-product, non-interfering post type. 'page' is often safe.
                 $query->is_post_type_archive = false; // Explicitly set these flags
                 $query->is_archive = false;
            }
            // Setting $query->posts = array(); and $query->post_count = 0; might also be needed
            // if "no results" messages persist, but clearing 's' is often enough.
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
                null,
                true
            );
        }

        if (!wp_script_is('lupasearch-init', 'enqueued')) {
            wp_enqueue_script(
                'lupasearch-init',
                plugin_dir_url(__FILE__) . '../js/lupasearch-init.js', // Assuming js folder is one level up from includes
                array('lupasearch-client'), // Make sure lupasearch-client is loaded first
                null,
                true
            );
        }
        
        $ui_plugin_key = LupaSearch_Config::get_ui_plugin_key();
        
        if (!empty($ui_plugin_key)) {
            // Localize script only if not already done for 'lupasearch-init'
            // This check might be overly cautious or might need a more robust way 
            // to see if params are already localized if enqueue_scripts can be called multiple times.
            wp_localize_script('lupasearch-init', 'lupaSearchInitParams', array(
                'ui_plugin_key' => $ui_plugin_key,
            ));
        }
    }
}
