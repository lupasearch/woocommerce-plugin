<?php
/**
 * LupaSearch Sync Class
 * 
 * Compatible with WordPress 5.0+
 */

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

        // Add table name to $wpdb object for WPCS compatibility
        $wpdb->lupasearch_sync_log = $this->log_table_name;

        // Create log table if it doesn't exist
        $this->create_log_table();

        // Direct hooks are removed, will be handled by WP-Cron scheduling
    }

    private function create_log_table() {
        global $wpdb;

        // Check if the table already exists
        // Check cache first
        $cache_key = 'table_exists_' . $this->log_table_name;
        $table_exists = wp_cache_get($cache_key);

        if (false === $table_exists) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
            $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->log_table_name));
            
            wp_cache_set($cache_key, $table_exists, '', 3600);
        }
        if ($table_exists == $this->log_table_name) {
            // Table already exists, so we don't need to do anything further.
            return;
        }
        
        // Table does not exist, so create it.
        $charset_collate = $wpdb->get_charset_collate();
        // dbDelta requires the full SQL with table name, cannot use %i placeholder
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
            // get_data_for_single_product now returns an array of data arrays.
            // If $product_object is a variable parent, it returns [parent_data, var1_data, var2_data,...]
            // If $product_object is simple/variation, it returns [product_data]
            $products_data_to_sync = $product_provider->get_data_for_single_product($product_object);

            if (empty($products_data_to_sync)) {
                $this->log('auto_sync_update', $product_id, 'error', 'No product data found to sync.');
                return;
            }

            $all_successful = true;
            $messages = [];

            foreach ($products_data_to_sync as $individual_product_data) {
                if (empty($individual_product_data) || !isset($individual_product_data['id'])) {
                    $messages[] = "Skipped an empty or invalid product data item for original ID: {$product_id}.";
                    $all_successful = false;
                    continue;
                }
                
                $current_doc_id = $individual_product_data['id']; // This could be parent or variation ID

                $formatted_document = $this->generator->format_single_product_from_data($individual_product_data);
                if (!$formatted_document) {
                    $this->log('auto_sync_update', $current_doc_id, 'error', 'Failed to format product data for sync.');
                    $messages[] = "Failed to format data for doc ID: {$current_doc_id}.";
                    $all_successful = false;
                    continue;
                }
                
                $result = $this->send_to_api('update', array($formatted_document));
                
                if ($result['success']) {
                    $this->log('auto_sync_update', $current_doc_id, 'success', 'Document synced successfully via cron.');
                    $messages[] = "Doc ID {$current_doc_id}: Synced successfully.";
                } else {
                    $this->log('auto_sync_update', $current_doc_id, 'error', 'API Error: ' . $result['message']);
                    $messages[] = "Doc ID {$current_doc_id}: API Error - " . $result['message'];
                    $all_successful = false;
                }
            }
            // Overall status for the originally triggered product_id
            // Removed error_log statement

        } catch (Exception $e) {
            $this->log('auto_sync_update', $product_id, 'error', 'Exception during sync: ' . $e->getMessage());
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

    private function get_log_cache_version_key() {
        return 'lupasearch_log_cache_version';
    }

    private function get_log_cache_version() {
        $version = wp_cache_get($this->get_log_cache_version_key(), 'lupasearch_logs_meta');
        if (false === $version) {
            $version = time(); // Initialize with current timestamp
            wp_cache_set($this->get_log_cache_version_key(), $version, 'lupasearch_logs_meta', 0); // Persist indefinitely until next invalidation
        }
        return $version;
    }

    private function increment_log_cache_version() {
        // Incrementing or setting to new timestamp effectively invalidates previous versions
        $new_version = time(); 
        wp_cache_set($this->get_log_cache_version_key(), $new_version, 'lupasearch_logs_meta', 0);
        return $new_version;
    }

    public function log($action, $product_id, $status, $message) { // Changed to public
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
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

        $this->increment_log_cache_version();

        // Automatic Log Pruning
        $this->prune_logs();
    }

    private function prune_logs($max_entries = 500) {
        global $wpdb;

        // Get current count - no caching needed as this is for immediate pruning decision
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $current_count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->lupasearch_sync_log");
        
        if ($current_count > $max_entries) {
            $entries_to_delete = $current_count - $max_entries;
            
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->lupasearch_sync_log ORDER BY created_at ASC LIMIT %d", $entries_to_delete));
        }
    }

    public function get_logs($limit = 20, $offset = 0) { // Default limit 20
        global $wpdb;
        $version = $this->get_log_cache_version();
        $cache_key = "lupasearch_logs_v{$version}_limit_{$limit}_offset_{$offset}";
        $cache_group = 'lupasearch_logs'; 
        
        $logs = wp_cache_get($cache_key, $cache_group);

        if (false === $logs) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $logs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $wpdb->lupasearch_sync_log ORDER BY created_at DESC LIMIT %d, %d", $offset, $limit));
            wp_cache_set($cache_key, $logs, $cache_group, HOUR_IN_SECONDS); // Cache for 1 hour
        }
        return $logs;
    }

    public function get_total_logs_count() {
        global $wpdb;
        $version = $this->get_log_cache_version();
        $cache_key = "lupasearch_log_count_v{$version}";
        $cache_group = 'lupasearch_logs';

        $count = wp_cache_get($cache_key, $cache_group);

        if (false === $count) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $count = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->lupasearch_sync_log");
            wp_cache_set($cache_key, $count, $cache_group, HOUR_IN_SECONDS); // Cache for 1 hour
        }
        return (int) $count;
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

    public function delete_all_documents_from_index() {
        $index_id = LupaSearch_Config::get_product_index_id();
        $api_key = LupaSearch_Config::get_api_key();

        if (empty($index_id) || empty($api_key)) {
            $this->log('delete_all', null, 'error', 'Missing API configuration.');
            return array('success' => false, 'message' => 'Missing API configuration for LupaSearch.');
        }

        // Using POST /v1/indices/{indexId}/documents/batchDelete as per provided documentation
        // This requires fetching all product IDs first.

        $this->log('delete_all', null, 'info', 'Starting deletion of all known products from index: ' . $index_id);

        $product_provider = new LupaSearch_Product_Provider();
        // Get all publishable product IDs from WooCommerce
        // Note: This might be memory intensive for extremely large stores.
        // Consider if LupaSearch has a "scroll" or "export IDs" API if this becomes an issue.
        $all_product_ids_query_args = array(
            'status' => array('publish', 'draft', 'pending', 'private', 'trash'), // Get all possible statuses to ensure we clear them
            'limit'  => -1,
            'return' => 'ids',
        );
        $product_ids_to_delete = wc_get_products($all_product_ids_query_args);

        if (empty($product_ids_to_delete)) {
            $this->log('delete_all', null, 'success', 'No products found in WooCommerce to delete from LupaSearch index.');
            return array('success' => true, 'message' => 'No WooCommerce products to delete from index.');
        }
        
        // Convert IDs to string as per API example {"ids": ["1", "2"]}
        $product_ids_to_delete_str = array_map('strval', $product_ids_to_delete);

        $url = "https://api.lupasearch.com/v1/indices/{$index_id}/documents/batchDelete";
        $batch_size_api = 500; // Adjust batch size as per LupaSearch API recommendations or limits
        $id_batches = array_chunk($product_ids_to_delete_str, $batch_size_api);
        $overall_success = true;
        $all_messages = [];

        foreach ($id_batches as $batch_of_ids) {
            $args = array(
                'method'  => 'POST',
                'headers' => array(
                    'X-Lupa-Api-Key' => $api_key,
                    'Content-Type' => 'application/json',
                ),
                'body'    => json_encode(array('ids' => $batch_of_ids)),
                'timeout' => 60, // This operation might take longer
            );

            $this->log('delete_all_batch', null, 'info', 'Attempting to batch delete ' . count($batch_of_ids) . ' documents.');
            $response = wp_remote_post($url, $args);

            if (is_wp_error($response)) {
                $error_message = 'WP Error during batch delete: ' . $response->get_error_message();
                $this->log('delete_all_batch', null, 'error', $error_message);
                $all_messages[] = $error_message;
                $overall_success = false;
                continue; // Try next batch if one fails, or break if critical
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            // LupaSearch API for batchDelete: Assuming 200 OK with a success body, or specific success codes.
            // The provided doc snippet doesn't specify response for batchDelete.
            // Let's assume a 200 or 202 (Accepted) or 204 (No Content if it processes and there's nothing to report)
            // and a body like { "success": true } or similar for 200.
            if ($status_code >= 200 && $status_code < 300 ) { // General success range
                 // Check for specific success indicators if API provides them
                if (isset($body['success']) && $body['success'] === false) {
                    $error_message = isset($body['errors']['message']) ? $body['errors']['message'] : (isset($body['message']) ? $body['message'] : 'Batch delete API call reported failure. Status: ' . $status_code);
                    $this->log('delete_all_batch', null, 'error', $error_message . ' Batch IDs: ' . implode(',', $batch_of_ids));
                    $all_messages[] = $error_message;
                    $overall_success = false;
                } else {
                    $success_message_batch = 'Batch delete successful for ' . count($batch_of_ids) . ' documents. Status: ' . $status_code;
                    $this->log('delete_all_batch', null, 'success', $success_message_batch);
                    $all_messages[] = $success_message_batch;
                }
            } else {
                $error_message = isset($body['errors']['message']) ? $body['errors']['message'] : (isset($body['message']) ? $body['message'] : 'Batch delete failed. Status: ' . $status_code);
                $this->log('delete_all_batch', null, 'error', $error_message . ' Batch IDs: ' . implode(',', $batch_of_ids));
                $all_messages[] = $error_message;
                $overall_success = false;
            }
        }

        $final_message = implode('; ', $all_messages);
        if ($overall_success) {
            $this->log('delete_all', null, 'success', 'Finished deleting all known products. ' . $final_message);
            return array('success' => true, 'message' => 'Successfully deleted all known products from index. ' . $final_message);
        } else {
            $this->log('delete_all', null, 'error', 'Failed to delete some or all products. ' . $final_message);
            return array('success' => false, 'message' => 'Failed to delete some or all products. ' . $final_message);
        }
    }

    public function clear_all_logs() {
        global $wpdb;
        
        // TRUNCATE TABLE is faster if permissions allow.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->query("TRUNCATE TABLE $wpdb->lupasearch_sync_log");
        
        if ($result !== false) {
            $this->increment_log_cache_version();
            return true;
        } else {
            // Log this error to PHP error log or a separate persistent admin notice if critical
            return false;
        }
    }
}