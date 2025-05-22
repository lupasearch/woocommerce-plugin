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

        // Direct hooks are removed, will be handled by WP-Cron scheduling
    }

    private function create_log_table() {
        global $wpdb;

        // Check if the table already exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->log_table_name));

        if ($table_exists == $this->log_table_name) {
            // Table already exists, so we don't need to do anything further.
            return;
        }
        
        // Table does not exist, so create it.
        $charset_collate = $wpdb->get_charset_collate();
        // The IF NOT EXISTS is good practice here, even if we checked above,
        // as dbDelta might be called from multiple places or in race conditions.
        // However, dbDelta itself is designed to handle this. Let's keep it clean.
        $sql = "CREATE TABLE `{$this->log_table_name}` (
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
        $product_object = wc_get_product($product_id);
        if (!$product_object) {
            $this->log('auto_sync_update', $product_id, 'error', 'Product not found for sync.');
            return;
        }

        // Instantiate Product Provider here
        $product_provider = new LupaSearch_Product_Provider();

        try {
            $product_data = $product_provider->get_data_for_single_product($product_object);
            if (!$product_data) {
                $this->log('auto_sync_update', $product_id, 'error', 'Failed to retrieve product data for sync.');
                return;
            }

            // $this->generator is available from __construct
            $formatted_document = $this->generator->format_single_product_from_data($product_data);
            if (!$formatted_document) {
                $this->log('auto_sync_update', $product_id, 'error', 'Failed to format product data for sync.');
                return;
            }
            
            $result = $this->send_to_api('update', array($formatted_document)); // send_to_api expects an array of documents
            
            if ($result['success']) {
                $this->log('auto_sync_update', $product_id, 'success', 'Product synced successfully via cron.');
            } else {
                $this->log('auto_sync_update', $product_id, 'error', 'API Error: ' . $result['message']);
            }
        } catch (Exception $e) {
            $this->log('auto_sync_update', $product_id, 'error', 'Exception: ' . $e->getMessage());
        }
    }

    public function delete_product($product_id) { // Changed parameter name for clarity
        // Product ID is passed directly, no need to check post_type if it's from a reliable source (cron)
        // or if the product might already be deleted from DB.
        try {
            // The send_to_api method will handle the actual product_id for deletion
            $result = $this->send_to_api('delete', $product_id); 
            
            if ($result['success']) {
                $this->log('auto_sync_delete', $product_id, 'success', 'Product deleted from index via cron.');
            } else {
                $this->log('auto_sync_delete', $product_id, 'error', 'API Error: ' . $result['message']);
            }
        } catch (Exception $e) {
            $this->log('auto_sync_delete', $product_id, 'error', 'Exception: ' . $e->getMessage());
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
            return array('success' => false, 'message' => 'Missing API configuration for LupaSearch.');
        }

        $args = array(
            'headers' => array(
                'X-Lupa-Api-Key' => $api_key,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 30
        );

        if ($action === 'delete') {
            // $data here is the product_id
            $product_id_to_delete = $data;
            $url = "https://api.lupasearch.com/v1/indices/{$index_id}/documents/{$product_id_to_delete}";
            $args['method'] = 'DELETE';
            // No body for DELETE
        } elseif ($action === 'update') {
            // $data here is an array of documents, e.g., array($formatted_document)
            $url = "https://api.lupasearch.com/v1/indices/{$index_id}/documents";
            $args['method'] = 'POST';
            $args['body'] = json_encode(array('documents' => $data));
        } else {
            return array('success' => false, 'message' => 'Invalid API action specified.');
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return array('success' => false, 'message' => 'WP Error: ' . $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        $is_successful_response = false;
        $api_message = 'API request processed. Status: ' . $status_code;

        if ($action === 'delete') {
            if ($status_code === 204) { // Successfully deleted
                $is_successful_response = true;
                $api_message = 'Document successfully deleted from index.';
            } elseif ($status_code === 404) { // Document not found, which means it's already gone
                $is_successful_response = true; // Treat as success for our purpose
                $api_message = 'Document not found in index (already deleted or never existed).';
            }
        } elseif ($action === 'update') {
            if ($status_code === 200 && isset($body['success']) && $body['success'] === true) {
                $is_successful_response = true;
                $api_message = isset($body['message']) ? $body['message'] : 'Document successfully updated/created.';
            } elseif ($status_code === 200 && !isset($body['success'])) {
                // If LupaSearch can return 200 for updates without a 'success' flag, adjust here.
                // For now, sticking to requiring 'success: true' for POST.
                $is_successful_response = false; 
            }
        }

        // Override api_message if specific error messages are available from the body
        if (isset($body['errors']['message'])) {
            $api_message = $body['errors']['message'];
        } elseif (isset($body['message']) && !$is_successful_response) { // Use body message if not already a success message
            $api_message = $body['message'];
        }
        
        return array(
            'success' => $is_successful_response,
            'message' => $api_message
        );
    }

    public function log($action, $product_id, $status, $message) { // Changed to public
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

        // Automatic Log Pruning
        $this->prune_logs();
    }

    private function prune_logs($max_entries = 500) {
        global $wpdb;
        
        $current_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->log_table_name}");
        
        if ($current_count > $max_entries) {
            $entries_to_delete = $current_count - $max_entries;
            // Delete the oldest entries
            // Note: Using a subquery like this might be slow on very large tables without proper indexing on created_at.
            // For MySQL, a more performant way for large deletes might be to find the ID of the Nth oldest record and delete by ID.
            // However, for a few hundred to a thousand excess rows, this should be acceptable.
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$this->log_table_name} ORDER BY created_at ASC LIMIT %d",
                    $entries_to_delete
                )
            );
        }
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

    public static function handle_sync_product_cron_event($product_id) {
        // Ensure LupaSearch_Sync class is loaded if not already (e.g. via autoloader or direct include)
        // For simplicity, assuming it's available or handle class loading as per plugin structure.
        $instance = new self(); 
        $instance->sync_product($product_id);
    }

    public static function handle_delete_product_cron_event($product_id) {
        $instance = new self();
        $instance->delete_product($product_id);
    }

    public function clear_all_logs() {
        global $wpdb;
        // TRUNCATE TABLE is faster if permissions allow and we don't need to return the number of deleted rows.
        // DELETE FROM is also fine.
        $result = $wpdb->query("TRUNCATE TABLE {$this->log_table_name}");
        // $wpdb->query returns number of affected rows for DELETE, FALSE on error for TRUNCATE (depending on DB driver/version)
        // For TRUNCATE, a more reliable check might be if $wpdb->last_error is empty.
        // Let's assume if it doesn't return false, it's okay.
        if ($result === false) {
            // Log this error to PHP error log or a separate persistent admin notice if critical
            error_log("LupaSearch: Failed to clear logs from table {$this->log_table_name}. DB Error: " . $wpdb->last_error);
            return false;
        }
        return true;
    }
}
