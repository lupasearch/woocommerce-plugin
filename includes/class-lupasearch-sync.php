<?php
if (!defined('ABSPATH')) {
    exit;
}

class LupaSearch_Sync {
    private $log_table_name;
    private $generator;

    public function __construct() {
        global $wpdb;
        $this->log_table_name = $wpdb->prefix . 'lupasearch_sync_log';
        $this->generator = new LupaSearch_Document_Generator();

        // Create log table if it doesn't exist
        $this->create_log_table();

        // Hook into product changes if auto-sync is enabled
        if (get_option('lupasearch_auto_sync', false)) {
            add_action('woocommerce_update_product', array($this, 'sync_product'), 10, 1);
            add_action('woocommerce_create_product', array($this, 'sync_product'), 10, 1);
            add_action('before_delete_post', array($this, 'delete_product'), 10, 1);
        }
    }

    private function create_log_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $this->log_table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            action varchar(50) NOT NULL,
            product_id bigint(20),
            status varchar(20) NOT NULL,
            message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function sync_product($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            $this->log('sync', $product_id, 'error', 'Product not found');
            return;
        }

        try {
            $formatted_product = $this->generator->format_single_product($product);
            $result = $this->send_to_api('update', array($formatted_product));
            
            if ($result['success']) {
                $this->log('sync', $product_id, 'success', 'Product synced successfully');
            } else {
                $this->log('sync', $product_id, 'error', $result['message']);
            }
        } catch (Exception $e) {
            $this->log('sync', $product_id, 'error', $e->getMessage());
        }
    }

    public function delete_product($post_id) {
        if (get_post_type($post_id) !== 'product') {
            return;
        }

        try {
            $result = $this->send_to_api('delete', array('id' => $post_id));
            
            if ($result['success']) {
                $this->log('delete', $post_id, 'success', 'Product deleted from index');
            } else {
                $this->log('delete', $post_id, 'error', $result['message']);
            }
        } catch (Exception $e) {
            $this->log('delete', $post_id, 'error', $e->getMessage());
        }
    }

    public function reindex_all() {
        try {
            $this->log('reindex', null, 'info', 'Starting full reindex');
            $result = $this->generator->generate_documents();
            
            if ($result['success']) {
                $import_result = $this->generator->import_documents_via_api($result['data']);
                
                if ($import_result['success']) {
                    $this->log('reindex', null, 'success', 'Full reindex completed successfully');
                    return array('success' => true, 'message' => 'Reindex completed successfully');
                }
            }
            
            $this->log('reindex', null, 'error', $result['message']);
            return array('success' => false, 'message' => $result['message']);
        } catch (Exception $e) {
            $this->log('reindex', null, 'error', $e->getMessage());
            return array('success' => false, 'message' => $e->getMessage());
        }
    }

    private function send_to_api($action, $data) {
        $index_id = LupaSearch_Config::get_product_index_id();
        $api_key = LupaSearch_Config::get_api_key();

        if (empty($index_id) || empty($api_key)) {
            return array('success' => false, 'message' => 'Missing configuration');
        }

        $url = "https://api.lupasearch.com/v1/indices/{$index_id}/documents";
        
        $args = array(
            'headers' => array(
                'X-Lupa-Api-Key' => $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array('documents' => $data)),
            'method' => 'POST',
            'timeout' => 30
        );

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return array(
            'success' => wp_remote_retrieve_response_code($response) === 200,
            'message' => isset($body['message']) ? $body['message'] : 'Unknown error'
        );
    }

    private function log($action, $product_id, $status, $message) {
        global $wpdb;
        
        $wpdb->insert(
            $this->log_table_name,
            array(
                'action' => $action,
                'product_id' => $product_id,
                'status' => $status,
                'message' => $message
            ),
            array('%s', '%d', '%s', '%s')
        );
    }

    public function get_logs($limit = 100) {
        global $wpdb;
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->log_table_name} ORDER BY created_at DESC LIMIT %d",
                $limit
            )
        );
    }
}
