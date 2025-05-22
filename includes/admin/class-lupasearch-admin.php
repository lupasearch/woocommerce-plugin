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
            'LupaSearch', 
            'LupaSearch',
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
            array('jquery'),
            '1.0.0',
            true
        );

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
                            General Setup
                        </a>
                        <a href="?page=lupasearch&tab=api" 
                           class="nav-tab <?php echo $active_tab == 'api' ? 'nav-tab-active' : ''; ?>">
                            API Setup
                        </a>
                        <a href="?page=lupasearch&tab=design" 
                           class="nav-tab <?php echo $active_tab == 'design' ? 'nav-tab-active' : ''; ?>">
                            Design
                        </a>
                        <a href="?page=lupasearch&tab=logs" 
                           class="nav-tab <?php echo $active_tab == 'logs' ? 'nav-tab-active' : ''; ?>">
                            Logs
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
                             alt="LupaSearch Logo">
                        <a href="<?php echo esc_url('https://console.lupasearch.com/dashboard/' . esc_attr(LupaSearch_Config::get_organization()) . '/analytics'); ?>" 
                           target="_blank" 
                           class="button button-primary button-large">
                            Visit LupaSearch Console
                        </a>
                    </div>

                    <h3>Configuration Status</h3>
                    <?php $this->render_configuration_status($config_status); ?>

                    <div class="lupasearch-connection">
                        <h3>Connection Status</h3>
                        <div class="lupasearch-connection-status">
                            <div class="connection-header">
                                <button type="button" id="test-connection" class="button button-secondary">
                                    Test Connection
                                </button>
                                <span id="connection-status"></span>
                            </div>
                            <div id="connection-details" class="connection-details" style="display: none;">
                                <div class="stats-grid">
                                    <div class="stat-item">
                                        <label>Total Products:</label>
                                        <strong><?php echo esc_html($total_products); ?></strong>
                                    </div>
                                    <div class="stat-item">
                                        <label>Indexed Products:</label>
                                        <strong id="indexed-products">-</strong>
                                    </div>
                                    <div class="stat-item">
                                        <label>Active Indices:</label>
                                        <strong id="active-indices">-</strong>
                                    </div>
                                </div>
                                <div id="available-indices" class="indices-list"></div>
                            </div>
                        </div>
                    </div>

                    <div class="lupasearch-getting-started">
                        <h3>Getting Started</h3>
                        <ul>
                            <li>
                                <a href="https://console.lupasearch.com/login" target="_blank">
                                    Get API Keys →
                                </a>
                            </li>
                            <li>
                                <a href="https://console.lupasearch.com/dashboard/<?php echo esc_attr(LupaSearch_Config::get_organization()); ?>/woocommerce/plugin" target="_blank">
                                    Plugin Configuration →
                                </a>
                            </li>
                            <li>
                                <a href="https://console.lupasearch.com/dashboard/<?php echo esc_attr(LupaSearch_Config::get_organization()); ?>/woocommerce/analytics" target="_blank">
                                    Search Analytics →
                                </a>
                            </li>
                            <li>
                                <a href="https://docs.lupasearch.com/guides/woocommerce" target="_blank">
                                    Documentation →
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
        ?>
        <div class="lupasearch-general-tab">
            <table class="form-table">
                <tr>
                    <th scope="row">Automatic Sync</th>
                    <td>
                        <label>
                            <input type="checkbox" name="lupasearch_auto_sync" value="1" <?php checked($sync_enabled); ?>>
                            Enable automatic product sync
                        </label>
                        <p class="description">Automatically sync product changes to LupaSearch</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Manual Sync</th>
                    <td>
                        <button type="button" id="reindex-all" class="button button-primary">
                            Reindex All Products
                        </button>
                        <span id="reindex-status"></span>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Document Generation</th>
                    <td>
                        <button type="button" id="generate-documents" class="button button-secondary">
                            Generate Documents JSON
                        </button>
                        <button type="button" id="import-documents" class="button button-primary">
                            Import to LupaSearch
                        </button>
                        <span id="generation-status"></span>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>

            <div class="lupasearch-documentation">
                <div class="lupasearch-documentation-columns">
                    <div class="documentation-column">
                        <h3>Import Instructions</h3>
                        <ol class="steps-list">
                            <li>
                                <span class="step-number">1</span>
                                <div class="step-content">
                                    <h4>Download Import File</h4>
                                    <p>Click "Generate Documents JSON" to download the product data file</p>
                                </div>
                            </li>
                            <li>
                                <span class="step-number">2</span>
                                <div class="step-content">
                                    <h4>Navigate to Document Import Page</h4>
                                    <p>Go to <a href="<?php echo esc_url('https://console.lupasearch.com/dashboard/' . esc_attr(LupaSearch_Config::get_organization()) . '/woocommerce/indices'); ?>" target="_blank">LupaSearch Console → Indices</a></p>
                                </div>
                            </li>
                            <li>
                                <span class="step-number">Ʒ</span>
                                <div class="step-content">
                                    <h4>Start New Import</h4>
                                    <p>Click the "New Import" button in the LupaSearch Console</p>
                                </div>
                            </li>
                            <li>
                                <span class="step-number">4</span>
                                <div class="step-content">
                                    <h4>Import JSON Data</h4>
                                    <p>Copy and paste the contents of the downloaded JSON file into the import field</p>
                                </div>
                            </li>
                        </ol>
                    </div>

                    <div class="documentation-column">
                        <h3>Sample Document Structure</h3>
                        <pre><?php echo esc_html(json_encode(array(
                            "id" => "degnv3g4ui",
                            "name" => "sample text",
                            "brand" => "sample text",
                            "color" => "sample text",
                            "image" => "sample text",
                            "price" => 1.23,
                            "author" => "sample text",
                            "gender" => "sample text",
                            "rating" => 10,
                            "category" => "sample text",
                            "description" => "sample text",
                            "alternativeImages" => "sample text",
                            "url" => "https://example.com/product/sample-product"
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
                <th scope="row">Organization Name</th>
                <td>
                    <input type="text" name="<?php echo LupaSearch_Config::OPTION_ORGANIZATION; ?>"
                           value="<?php echo esc_attr(LupaSearch_Config::get_organization()); ?>" class="regular-text">
                    <p class="description">Enter your LupaSearch organization name</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Project Name</th>
                <td>
                    <input type="text" name="<?php echo LupaSearch_Config::OPTION_PROJECT; ?>"
                           value="<?php echo esc_attr(LupaSearch_Config::get_project()); ?>" class="regular-text">
                    <p class="description">Enter your LupaSearch project name</p>
                </td>
            </tr>
            <tr>
                <th scope="row">API Key</th>
                <td>
                    <input type="password" name="<?php echo LupaSearch_Config::OPTION_API_KEY; ?>"
                           value="<?php echo esc_attr(LupaSearch_Config::get_api_key()); ?>" class="regular-text">
                    <p class="description">Your LupaSearch API Key</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Product Index ID</th>
                <td>
                    <input type="text" name="<?php echo LupaSearch_Config::OPTION_PRODUCT_INDEX_ID; ?>"
                           value="<?php echo esc_attr(LupaSearch_Config::get_product_index_id()); ?>" class="regular-text">
                    <p class="description">Your LupaSearch Product Index ID</p>
                </td>
            </tr>
            <tr>
                <th scope="row">UI Plugin Configuration Key</th>
                <td>
                    <input type="text" name="<?php echo LupaSearch_Config::OPTION_UI_PLUGIN_KEY; ?>"
                           value="<?php echo esc_attr(LupaSearch_Config::get_ui_plugin_key()); ?>" class="regular-text">
                    <p class="description">Your LupaSearch UI Plugin Configuration Key</p>
                </td>
            </tr>
        </table>
        <?php
    }

    // Add new method for logs tab
    private function render_logs_tab() {
        $sync = new LupaSearch_Sync();
        $logs = $sync->get_logs(50); // Increased limit to 50 for logs tab
        ?>
        <div class="lupasearch-sync-logs">
            <h3>Synchronization Activity Log</h3>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Action</th>
                        <th>Product ID</th>
                        <th>Status</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5">No synchronization activity recorded yet.</td>
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
                <h3>Integration Options</h3>
                <div class="integration-methods">
                    <div class="integration-method">
                        <h4>Shortcodes</h4>
                        <p>Use these shortcodes to add search functionality to any page or post:</p>
                        <div class="code-snippet">
                            <code>[lupa_search_block]</code>
                        </div>
                        <div class="description">Adds a search input box</div>


                        <div class="code-snippet">
                            <code>[lupa_search_results_block]</code>
                        </div>
                        <div class="description">Adds a search results container</div>

                    </div>

                    <div class="integration-method">
                        <h4>Gutenberg Blocks</h4>
                        <p>Use the built-in Gutenberg blocks:</p>
                        <ul>
                            <li><strong>LupaSearch Box</strong> - For adding a search input</li>
                            <li><strong>LupaSearch Results</strong> - For displaying search results</li>
                        </ul>
                    </div>

                    <div class="integration-method">
                        <h4>Widget</h4>
                        <p>Add the LupaSearch Box widget to any widget area in your theme using the WordPress Widgets screen.</p>
                    </div>
                </div>

                <div class="integration-example">
                    <h4>Example Implementation</h4>
                    <ol style="padding-left: 20px;">
                        <li>Add the search box to your header using the shortcode: <code>[lupa_search_block]</code></li>
                        <li>Create a new page for search results</li>
                        <li>Add the results block to that page: <code>[lupa_search_results_block]</code></li>
                    </ol>
                </div>
            </div>

        <div class="lupasearch-design-section">
            <h3>Search UI Builder</h3>
            <p class="description">
                Design and customize your search experience using our visual Search UI Builder. 
                Create a beautiful and functional search interface that matches your brand.
            </p>
            
         

            <p class="lupasearch-builder-cta">
                <a href="<?php echo esc_url('https://console.lupasearch.com/dashboard/' . esc_attr($organization) . '/woocommerce/builder'); ?>" 
                   target="_blank" 
                   class="button button-primary button-hero">
                    Open Search UI Builder
                </a>
            </p>
            
            <div class="lupasearch-builder-features">
                <ul>
                    <li>Customize search box appearance</li>
                    <li>Design search results layout</li>
                    <li>Configure faceted filters</li>
                    <li>Set up sorting options</li>
                    <li>Adjust mobile responsiveness</li>
                    <li>Preview changes in real-time</li>
                </ul>
            </div>

         

            <div class="lupasearch-builder-preview">
                <img src="<?php echo esc_url(plugins_url('/images/search-builder.png', dirname(dirname(__FILE__)))); ?>" 
                     alt="Search UI Builder Preview">
            </div>
        </div>
        <?php
    }

    private function get_configuration_status() {
        return array(
            'organization' => array(
                'status' => !empty(LupaSearch_Config::get_organization()),
                'label' => 'Organization Name'
            ),
            'project' => array(
                'status' => !empty(LupaSearch_Config::get_project()),
                'label' => 'Project Name'
            ),
            'api_key' => array(
                'status' => !empty(LupaSearch_Config::get_api_key()),
                'label' => 'API Key'
            ),
            'ui_plugin_key' => array(
                'status' => !empty(LupaSearch_Config::get_ui_plugin_key()),
                'label' => 'UI Plugin Key'
            ),
            'product_index' => array(
                'status' => !empty(LupaSearch_Config::get_product_index_id()),
                'label' => 'Product Index ID'
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
            wp_send_json_error('Unauthorized');
        }
        
        $product_provider = new LupaSearch_Product_Provider();
        $result = $product_provider->test_lupa_connection();
        
        wp_send_json($result);
    }

    public function ajax_generate_documents() {
        check_ajax_referer('lupasearch-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $generator = new LupaSearch_Document_Generator();
        $result = $generator->generate_documents();
        
        wp_send_json($result);
    }

    public function ajax_import_documents() {
        check_ajax_referer('lupasearch-admin-nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
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

    // Add this new method to handle unauthorized requests
    public function handle_unauthorized_request() {
        wp_send_json_error(array(
            'success' => false,
            'message' => 'Unauthorized access'
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
            wp_die('Unauthorized');
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
        }

        wp_redirect(add_query_arg('tab', $active_tab, admin_url('admin.php?page=lupasearch')));
        exit;
    }
}