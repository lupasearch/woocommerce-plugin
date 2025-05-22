<?php
if (!defined('ABSPATH')) {
    exit;
}

class LupaSearch_Admin {
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // AJAX handlers for logged-in users
        add_action('wp_ajax_test_lupa_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_generate_lupasearch_documents', array($this, 'ajax_generate_documents'));
        add_action('wp_ajax_import_lupasearch_documents', array($this, 'ajax_import_documents'));
        add_action('wp_ajax_clear_lupasearch_logs', array($this, 'ajax_clear_lupasearch_logs')); // New AJAX handler
        
        // AJAX handlers for non-logged-in users
        add_action('wp_ajax_nopriv_test_lupa_connection', array($this, 'handle_unauthorized_request'));
        add_action('wp_ajax_nopriv_generate_lupasearch_documents', array($this, 'handle_unauthorized_request'));
        add_action('wp_ajax_nopriv_import_lupasearch_documents', array($this, 'handle_unauthorized_request'));

        // Add filter to redirect back to active tab after saving
        add_filter('wp_redirect', array($this, 'settings_redirect'), 10, 2);

        // Replace default options saving with custom handler
        add_action('admin_init', array($this, 'save_settings'));
    }

    public function add_admin_menu() {
        add_menu_page(
            __('LupaSearch', 'lupasearch'), 
            __('LupaSearch', 'lupasearch'),
            'manage_options',
            'lupasearch',
            array($this, 'render_settings_page'),
            'dashicons-search'
        );
    }

    public function register_settings() {
        register_setting('lupasearch_options', LupaSearch_Config::OPTION_UI_PLUGIN_KEY);
        register_setting('lupasearch_options', LupaSearch_Config::OPTION_PRODUCT_INDEX_ID);
        register_setting('lupasearch_options', LupaSearch_Config::OPTION_API_KEY);
        register_setting('lupasearch_options', LupaSearch_Config::OPTION_ORGANIZATION);
        register_setting('lupasearch_options', LupaSearch_Config::OPTION_PROJECT);
        register_setting('lupasearch_options', 'lupasearch_auto_sync');
        register_setting('lupasearch_options', 'lupasearch_override_wp_search'); // New setting
    }

    public function enqueue_admin_scripts($hook) {
        if ('toplevel_page_lupasearch' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'lupasearch-admin',
            plugins_url('/css/admin.css', dirname(dirname(__FILE__))),
            array(),
            '1.0.0'
        );

        wp_enqueue_script(
            'lupasearch-admin',
            plugins_url('/js/admin.js', dirname(dirname(__FILE__))),
            array('jquery', 'wp-i18n'), // Added wp-i18n
            '1.0.0',
            true
        );

        // After enqueuing the script and its dependencies (including wp-i18n)
        // We can make the translations available to the script.
        wp_set_script_translations('lupasearch-admin', 'lupasearch', plugin_dir_path(dirname(__FILE__)) . 'languages');


        wp_localize_script('lupasearch-admin', 'lupaSearchAdmin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lupasearch-admin-nonce')
        ));
    }

    public function render_settings_page() {
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
        $product_provider = new LupaSearch_Product_Provider();
        $total_products = $product_provider->get_total_products();
        
        // Get configuration status
        $config_status = $this->get_configuration_status();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="lupasearch-admin-wrap">
                <div class="lupasearch-main-content">
                    <h2 class="nav-tab-wrapper">
                        <a href="?page=lupasearch&tab=general" 
                           class="nav-tab <?php echo $active_tab == 'general' ? 'nav-tab-active' : ''; ?>">
                            <?php esc_html_e('General Setup', 'lupasearch'); ?>
                        </a>
                        <a href="?page=lupasearch&tab=api" 
                           class="nav-tab <?php echo $active_tab == 'api' ? 'nav-tab-active' : ''; ?>">
                            <?php esc_html_e('API Setup', 'lupasearch'); ?>
                        </a>
                        <a href="?page=lupasearch&tab=design" 
                           class="nav-tab <?php echo $active_tab == 'design' ? 'nav-tab-active' : ''; ?>">
                            <?php esc_html_e('Design', 'lupasearch'); ?>
                        </a>
                        <a href="?page=lupasearch&tab=logs" 
                           class="nav-tab <?php echo $active_tab == 'logs' ? 'nav-tab-active' : ''; ?>">
                            <?php esc_html_e('Logs', 'lupasearch'); ?>
                        </a>
                    </h2>

                    <form action="" method="post">
                        <?php
                        wp_nonce_field('lupasearch_options-options');
                        echo '<input type="hidden" name="lupasearch_save_settings" value="1">';
                        echo '<input type="hidden" name="lupasearch_active_tab" value="' . esc_attr($active_tab) . '">';
                        
                        if ($active_tab == 'general') {
                            $this->render_general_tab($total_products);
                        } else if ($active_tab == 'api') {
                            $this->render_api_tab();
                        } else if ($active_tab == 'design') {
                            $this->render_design_tab();
                        } else if ($active_tab == 'logs') {
                            $this->render_logs_tab();
                        }
                        
                        // Only show submit button for non-general and non-logs tabs
                        if ($active_tab != 'general' && $active_tab != 'logs') {
                            submit_button();
                        }
                        ?>
                    </form>
                </div>

                <div class="lupasearch-sidebar">
                    <div class="lupasearch-logo">
                        <img src="<?php echo esc_url(plugins_url('/images/lupasearch-logo.png', dirname(dirname(__FILE__)))); ?>" 
                             alt="<?php esc_attr_e('LupaSearch Logo', 'lupasearch'); ?>">
                        <a href="<?php echo esc_url('https://console.lupasearch.com/dashboard/' . esc_attr(LupaSearch_Config::get_organization()) . '/analytics'); ?>" 
                           target="_blank" 
                           class="button button-primary button-large">
                            <?php esc_html_e('Visit LupaSearch Console', 'lupasearch'); ?>
                        </a>
                    </div>

                    <h3><?php esc_html_e('Configuration Status', 'lupasearch'); ?></h3>
                    <?php $this->render_configuration_status($config_status); ?>

                    <div class="lupasearch-connection">
                        <h3><?php esc_html_e('Connection Status', 'lupasearch'); ?></h3>
                        <div class="lupasearch-connection-status">
                            <div class="connection-header">
                                <button type="button" id="test-connection" class="button button-secondary">
                                    <?php esc_html_e('Test Connection', 'lupasearch'); ?>
                                </button>
                                <span id="connection-status"></span>
                            </div>
                            <div id="connection-details" class="connection-details" style="display: none;">
                                <div class="stats-grid">
                                    <div class="stat-item">
                                        <label><?php esc_html_e('Total Products:', 'lupasearch'); ?></label>
                                        <strong><?php echo esc_html($total_products); ?></strong>
                                    </div>
                                   
                                </div>
                                <div id="available-indices" class="indices-list"></div>
                            </div>
                        </div>
                    </div>

                    <div class="lupasearch-getting-started">
                        <h3><?php esc_html_e('Getting Started', 'lupasearch'); ?></h3>
                        <ul>
                            <li>
                                <a href="https://console.lupasearch.com/login" target="_blank">
                                    <?php esc_html_e('Get API Keys →', 'lupasearch'); ?>
                                </a>
                            </li>
                            <li>
                                <a href="https://console.lupasearch.com/dashboard/<?php echo esc_attr(LupaSearch_Config::get_organization()); ?>/woocommerce/plugin" target="_blank">
                                    <?php esc_html_e('Plugin Configuration →', 'lupasearch'); ?>
                                </a>
                            </li>
                            <li>
                                <a href="https://console.lupasearch.com/dashboard/<?php echo esc_attr(LupaSearch_Config::get_organization()); ?>/woocommerce/analytics" target="_blank">
                                    <?php esc_html_e('Search Analytics →', 'lupasearch'); ?>
                                </a>
                            </li>
                            <li>
                                <a href="https://docs.lupasearch.com/guides/woocommerce" target="_blank">
                                    <?php esc_html_e('Documentation →', 'lupasearch'); ?>
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_general_tab($total_products) {
        $sync_enabled = get_option('lupasearch_auto_sync', false);
        $override_wp_search_enabled = get_option('lupasearch_override_wp_search', true); // Default to true
        ?>
        <div class="lupasearch-general-tab">
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Automatic Sync', 'lupasearch'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="lupasearch_auto_sync" value="1" <?php checked($sync_enabled); ?>>
                            <?php esc_html_e('Enable automatic product sync', 'lupasearch'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('Automatically sync product changes to LupaSearch', 'lupasearch'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Override WordPress Search', 'lupasearch'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="lupasearch_override_wp_search" value="1" <?php checked($override_wp_search_enabled); ?>>
                            <?php esc_html_e('Enable LupaSearch on default WordPress search results page', 'lupasearch'); ?>
                        </label>
                        <p class="description"><?php esc_html_e('When enabled, LupaSearch will power the search results at URLs like', 'lupasearch'); ?> <code>/?s=query</code>.</p>
                    </td>
                </tr>
                <!-- TODO: Hiding now, for reviewing this feature later. -->
                <tr style="display:none;">
                    <th scope="row"><?php esc_html_e('Manual Sync', 'lupasearch'); ?></th>
                    <td>
                        <button type="button" id="reindex-all" class="button button-primary">
                            <?php esc_html_e('Reindex All Products', 'lupasearch'); ?>
                        </button>
                        <span id="reindex-status"></span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Document Generation', 'lupasearch'); ?></th>
                    <td>
                        <button type="button" id="generate-documents" class="button button-secondary">
                            <?php esc_html_e('Generate Documents JSON', 'lupasearch'); ?>
                        </button>
                        <button type="button" id="import-documents" class="button button-primary">
                            <?php esc_html_e('Import to LupaSearch', 'lupasearch'); ?>
                        </button>
                        <span id="generation-status"></span>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>

            <div class="lupasearch-documentation">
                <div class="lupasearch-documentation-columns">
                    <div class="documentation-column">
                        <h3><?php esc_html_e('Import Instructions', 'lupasearch'); ?></h3>
                        <ol class="steps-list">
                            <li>
                                <span class="step-number">1</span>
                                <div class="step-content">
                                    <h4><?php esc_html_e('Download Import File', 'lupasearch'); ?></h4>
                                    <p><?php esc_html_e('Click "Generate Documents JSON" to download the product data file', 'lupasearch'); ?></p>
                                </div>
                            </li>
                            <li>
                                <span class="step-number">2</span>
                                <div class="step-content">
                                    <h4><?php esc_html_e('Navigate to Document Import Page', 'lupasearch'); ?></h4>
                                    <p><?php printf(esc_html__('Go to %sLupaSearch Console → Indices%s', 'lupasearch'), '<a href="' . esc_url('https://console.lupasearch.com/dashboard/' . esc_attr(LupaSearch_Config::get_organization()) . '/woocommerce/indices') . '" target="_blank">', '</a>'); ?></p>
                                </div>
                            </li>
                            <li>
                                <span class="step-number">Ʒ</span>
                                <div class="step-content">
                                    <h4><?php esc_html_e('Start New Import', 'lupasearch'); ?></h4>
                                    <p><?php esc_html_e('Click the "New Import" button in the LupaSearch Console', 'lupasearch'); ?></p>
                                </div>
                            </li>
                            <li>
                                <span class="step-number">4</span>
                                <div class="step-content">
                                    <h4><?php esc_html_e('Import JSON Data', 'lupasearch'); ?></h4>
                                    <p><?php esc_html_e('Copy and paste the contents of the downloaded JSON file into the import field', 'lupasearch'); ?></p>
                                </div>
                            </li>
                        </ol>
                    </div>

                    <div class="documentation-column">
                        <h3><?php esc_html_e('Sample Document Structure', 'lupasearch'); ?></h3>
                        <pre><?php echo esc_html(json_encode(array(
                            "id" => "product_123",
                            "visibility" => "visible", // e.g., 'visible', 'catalog', 'search', 'hidden'
                            "description" => "This is a full product description with details.",
                            "description_short" => "A short and catchy description.",
                            "name" => "Awesome Product Name",
                            "price" => 29.99, // Regular price
                            "final_price" => 24.99, // Sale price, or same as regular if not on sale
                            "categories" => ["Electronics", "Gadgets"], // Array of category names
                            "category_ids" => [15, 25], // Array of category IDs
                            "images" => [
                                "https://example.com/product/image1.jpg",
                                "https://example.com/product/image2.jpg"
                            ], // Array of gallery image URLs
                            "main_image" => "https://example.com/product/main_image.jpg", // Main product image URL
                            "url" => "https://example.com/product/awesome-product-name",
                            "qty" => 50, // Stock quantity
                            "instock" => true, // Stock status (boolean)
                            "rating" => 4.5 // Average product rating
                            // You can also include other custom attributes here if configured
                            // "brand" => "SampleBrand",
                            // "color" => "Blue",
                        ), JSON_PRETTY_PRINT)); ?></pre>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_api_tab() {
        ?>
        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e('Organization Name', 'lupasearch'); ?></th>
                <td>
                    <input type="text" name="<?php echo LupaSearch_Config::OPTION_ORGANIZATION; ?>"
                           value="<?php echo esc_attr(LupaSearch_Config::get_organization()); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e('Enter your LupaSearch organization name', 'lupasearch'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Project Name', 'lupasearch'); ?></th>
                <td>
                    <input type="text" name="<?php echo LupaSearch_Config::OPTION_PROJECT; ?>"
                           value="<?php echo esc_attr(LupaSearch_Config::get_project()); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e('Enter your LupaSearch project name', 'lupasearch'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('API Key', 'lupasearch'); ?></th>
                <td>
                    <input type="password" name="<?php echo LupaSearch_Config::OPTION_API_KEY; ?>"
                           value="<?php echo esc_attr(LupaSearch_Config::get_api_key()); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e('Your LupaSearch API Key', 'lupasearch'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Product Index ID', 'lupasearch'); ?></th>
                <td>
                    <input type="text" name="<?php echo LupaSearch_Config::OPTION_PRODUCT_INDEX_ID; ?>"
                           value="<?php echo esc_attr(LupaSearch_Config::get_product_index_id()); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e('Your LupaSearch Product Index ID', 'lupasearch'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('UI Plugin Configuration Key', 'lupasearch'); ?></th>
                <td>
                    <input type="text" name="<?php echo LupaSearch_Config::OPTION_UI_PLUGIN_KEY; ?>"
                           value="<?php echo esc_attr(LupaSearch_Config::get_ui_plugin_key()); ?>" class="regular-text">
                    <p class="description">
                        <?php esc_html_e('Your LupaSearch UI Plugin Configuration Key.', 'lupasearch'); ?>
                        <?php
                        $organization = LupaSearch_Config::get_organization();
                        $project = LupaSearch_Config::get_project();
                        if (!empty($organization) && !empty($project)) {
                            $ui_key_url = sprintf(
                                'https://console.lupasearch.com/dashboard/%s/%s/plugin',
                                rawurlencode($organization),
                                rawurlencode($project)
                            );
                            echo ' <a href="' . esc_url($ui_key_url) . '" target="_blank">' . esc_html__('Get your UI Plugin Key here.', 'lupasearch') . '</a>';
                        }
                        ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    // Add new method for logs tab
    private function render_logs_tab() {
        $sync = new LupaSearch_Sync();
        $logs = $sync->get_logs(50); // Limit for display, not total logs
        ?>
        <div class="lupasearch-sync-logs">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <h3><?php esc_html_e('Synchronization Activity Log', 'lupasearch'); ?></h3>
                <button type="button" id="clear-lupasearch-logs" class="button button-secondary">
                    <?php esc_html_e('Clear All Logs', 'lupasearch'); ?>
                </button>
            </div>
            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Date', 'lupasearch'); ?></th>
                        <th><?php esc_html_e('Action', 'lupasearch'); ?></th>
                        <th><?php esc_html_e('Product ID', 'lupasearch'); ?></th>
                        <th><?php esc_html_e('Status', 'lupasearch'); ?></th>
                        <th><?php esc_html_e('Message', 'lupasearch'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5"><?php esc_html_e('No synchronization activity recorded yet.', 'lupasearch'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo esc_html($log->created_at); ?></td>
                                <td><?php echo esc_html($log->action); ?></td>
                                <td><?php echo $log->product_id ? esc_html($log->product_id) : '-'; ?></td>
                                <td>
                                    <span class="log-status <?php echo esc_attr($log->status); ?>">
                                        <?php echo esc_html($log->status); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($log->message); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // Add this new method
    private function render_design_tab() {
        $organization = LupaSearch_Config::get_organization();
        ?>
           <div class="lupasearch-integration-options">
                <h3><?php esc_html_e('Integration Options', 'lupasearch'); ?></h3>
                <div class="integration-methods">
                    <div class="integration-method">
                        <h4><?php esc_html_e('Shortcodes', 'lupasearch'); ?></h4>
                        <p><?php esc_html_e('Use these shortcodes to add search functionality to any page or post:', 'lupasearch'); ?></p>
                        <div class="code-snippet">
                            <code>[lupa_search_block]</code>
                        </div>
                        <div class="description"><?php esc_html_e('Adds a search input box', 'lupasearch'); ?></div>


                        <div class="code-snippet">
                            <code>[lupa_search_results_block]</code>
                        </div>
                        <div class="description"><?php esc_html_e('Adds a search results container', 'lupasearch'); ?></div>

                    </div>

                    <div class="integration-method">
                        <h4><?php esc_html_e('Gutenberg Blocks', 'lupasearch'); ?></h4>
                        <p><?php esc_html_e('Use the built-in Gutenberg blocks:', 'lupasearch'); ?></p>
                        <ul>
                            <li><strong><?php esc_html_e('LupaSearch Box', 'lupasearch'); ?></strong> - <?php esc_html_e('For adding a search input', 'lupasearch'); ?></li>
                            <li><strong><?php esc_html_e('LupaSearch Results', 'lupasearch'); ?></strong> - <?php esc_html_e('For displaying search results', 'lupasearch'); ?></li>
                        </ul>
                    </div>

                    <div class="integration-method">
                        <h4><?php esc_html_e('Widget', 'lupasearch'); ?></h4>
                        <p><?php esc_html_e('Add the LupaSearch Box widget to any widget area in your theme using the WordPress Widgets screen.', 'lupasearch'); ?></p>
                    </div>
                </div>

                <div class="integration-example">
                    <h4><?php esc_html_e('Example Implementation', 'lupasearch'); ?></h4>
                    <ol style="padding-left: 20px;">
                        <li><?php esc_html_e('Add the search box to your header using the shortcode:', 'lupasearch'); ?> <code>[lupa_search_block]</code></li>
                        <li><?php esc_html_e('Create a new page for search results', 'lupasearch'); ?></li>
                        <li><?php esc_html_e('Add the results block to that page:', 'lupasearch'); ?> <code>[lupa_search_results_block]</code></li>
                    </ol>
                </div>
            </div>

        <div class="lupasearch-design-section">
            <h3><?php esc_html_e('Search UI Builder', 'lupasearch'); ?></h3>
            <p class="description">
                <?php esc_html_e('Design and customize your search experience using our visual Search UI Builder.', 'lupasearch'); ?>
                <?php esc_html_e('Create a beautiful and functional search interface that matches your brand.', 'lupasearch'); ?>
            </p>
            
         

            <p class="lupasearch-builder-cta">
                <a href="<?php echo esc_url('https://console.lupasearch.com/dashboard/' . esc_attr($organization) . '/woocommerce/builder'); ?>" 
                   target="_blank" 
                   class="button button-primary button-hero">
                    <?php esc_html_e('Open Search UI Builder', 'lupasearch'); ?>
                </a>
            </p>
            
            <div class="lupasearch-builder-features">
                <ul>
                    <li><?php esc_html_e('Customize search box appearance', 'lupasearch'); ?></li>
                    <li><?php esc_html_e('Design search results layout', 'lupasearch'); ?></li>
                    <li><?php esc_html_e('Configure faceted filters', 'lupasearch'); ?></li>
                    <li><?php esc_html_e('Set up sorting options', 'lupasearch'); ?></li>
                    <li><?php esc_html_e('Adjust mobile responsiveness', 'lupasearch'); ?></li>
                    <li><?php esc_html_e('Preview changes in real-time', 'lupasearch'); ?></li>
                </ul>
            </div>

         

            <div class="lupasearch-builder-preview">
                <img src="<?php echo esc_url(plugins_url('/images/search-builder.png', dirname(dirname(__FILE__)))); ?>" 
                     alt="<?php esc_attr_e('Search UI Builder Preview', 'lupasearch'); ?>">
            </div>
        </div>
        <?php
    }

    private function get_configuration_status() {
        return array(
            'organization' => array(
                'status' => !empty(LupaSearch_Config::get_organization()),
                'label' => __('Organization Name', 'lupasearch')
            ),
            'project' => array(
                'status' => !empty(LupaSearch_Config::get_project()),
                'label' => __('Project Name', 'lupasearch')
            ),
            'api_key' => array(
                'status' => !empty(LupaSearch_Config::get_api_key()),
                'label' => __('API Key', 'lupasearch')
            ),
            'ui_plugin_key' => array(
                'status' => !empty(LupaSearch_Config::get_ui_plugin_key()),
                'label' => __('UI Plugin Key', 'lupasearch')
            ),
            'product_index' => array(
                'status' => !empty(LupaSearch_Config::get_product_index_id()),
                'label' => __('Product Index ID', 'lupasearch')
            )
        );
    }

    private function render_configuration_status($config_status) {
        $all_configured = true;
        foreach ($config_status as $item) {
            $icon = $item['status'] ? '✓' : '×';
            $class = $item['status'] ? 'status-success' : 'status-error';
            $all_configured = $all_configured && $item['status'];
            ?>
            <div class="lupasearch-status-item">
                <span class="status-icon <?php echo esc_attr($class); ?>">
                    <?php echo $icon; ?>
                </span>
                <span><?php echo esc_html($item['label']); ?></span>
            </div>
            <?php
        }

        if (!$all_configured) {
            ?>
            <p class="api-settings-cta">
                <a href="<?php echo esc_url(admin_url('admin.php?page=lupasearch&tab=api')); ?>" 
                   class="button button-secondary">
                    <?php esc_html_e('Go to API Settings', 'lupasearch'); ?>
                </a>
            </p>
            <?php
        }
    }

    public function ajax_test_connection() {
        check_ajax_referer('lupasearch-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'lupasearch'));
        }
        
        $product_provider = new LupaSearch_Product_Provider();
        $result = $product_provider->test_lupa_connection();
        
        wp_send_json($result);
    }

    public function ajax_generate_documents() {
        check_ajax_referer('lupasearch-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'lupasearch'));
        }
        
        $generator = new LupaSearch_Document_Generator();
        $result = $generator->generate_documents();
        
        wp_send_json($result);
    }

    public function ajax_import_documents() {
        check_ajax_referer('lupasearch-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'lupasearch'));
        }
        
        $generator = new LupaSearch_Document_Generator();
        $documents = $generator->generate_documents();
        
        if (!$documents['success']) {
            wp_send_json($documents);
            return;
        }
        
        $result = $generator->import_documents_via_api($documents['data']);
        wp_send_json($result);
    }

    public function ajax_clear_lupasearch_logs() {
        check_ajax_referer('lupasearch-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', 'lupasearch'), 403);
            return;
        }
        
        $sync = new LupaSearch_Sync();
        $result = $sync->clear_all_logs(); // This method needs to be created in LupaSearch_Sync
        
        if ($result) {
            wp_send_json_success(array('message' => __('All logs cleared successfully.', 'lupasearch')));
        } else {
            wp_send_json_error(array('message' => __('Failed to clear logs.', 'lupasearch')));
        }
    }

    // Add this new method to handle unauthorized requests
    public function handle_unauthorized_request() {
        wp_send_json_error(array(
            'success' => false,
            'message' => __('Unauthorized access', 'lupasearch')
        ), 403);
    }

    public function settings_redirect($location) {
        if (strpos($location, 'options-general.php') !== false && 
            isset($_POST['lupasearch_active_tab'])) {
            $active_tab = sanitize_text_field($_POST['lupasearch_active_tab']);
            $location = add_query_arg('tab', $active_tab, admin_url('admin.php?page=lupasearch'));
        }
        return $location;
    }

    public function save_settings() {
        if (!isset($_POST['lupasearch_save_settings'])) {
            return;
        }

        check_admin_referer('lupasearch_options-options');

        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized', 'lupasearch'));
        }

        // Save all API-related settings regardless of active tab
        $api_settings = array(
            LupaSearch_Config::OPTION_ORGANIZATION,
            LupaSearch_Config::OPTION_PROJECT,
            LupaSearch_Config::OPTION_API_KEY,
            LupaSearch_Config::OPTION_PRODUCT_INDEX_ID,
            LupaSearch_Config::OPTION_UI_PLUGIN_KEY,
        );

        foreach ($api_settings as $option) {
            if (isset($_POST[$option])) {
                update_option($option, sanitize_text_field($_POST[$option]));
            }
        }

        // Save tab-specific settings
        $active_tab = isset($_POST['lupasearch_active_tab']) ? sanitize_text_field($_POST['lupasearch_active_tab']) : 'general';

        if ($active_tab === 'general') {
            if (isset($_POST['lupasearch_auto_sync'])) {
                update_option('lupasearch_auto_sync', !empty($_POST['lupasearch_auto_sync']));
            } else {
                update_option('lupasearch_auto_sync', false);
            }

            if (isset($_POST['lupasearch_override_wp_search'])) {
                update_option('lupasearch_override_wp_search', !empty($_POST['lupasearch_override_wp_search']));
            } else {
                update_option('lupasearch_override_wp_search', false);
            }
        }

        wp_redirect(add_query_arg('tab', $active_tab, admin_url('admin.php?page=lupasearch')));
        exit;
    }
}
