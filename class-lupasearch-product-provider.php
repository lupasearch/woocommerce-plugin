<?php
if (!defined('ABSPATH')) {
    exit;
}

class LupaSearch_Product_Provider {
    public function get_products($page = 1, $limit = 20) {
        $args = array(
            'status' => 'publish',
            'limit' => $limit,
            'page' => $page,
            'orderby' => 'ID',
            'order' => 'ASC',
        );
        
        $products = wc_get_products($args);
        $formatted_products = array();
        
        foreach ($products as $product) {
            $formatted_products[] = array(
                'id' => $product->get_id(),
                'title' => $product->get_name(),
                'description' => $product->get_description(),
                'description_short' => $product->get_short_description(),
                'price' => $product->get_regular_price(),
                'final_price' => $product->get_price(),
                'categories' => $this->get_product_categories($product),
                'images' => $this->get_product_images($product),
                'main_image' => $this->get_main_image_url($product),
                'url' => $product->get_permalink(),
            );
        }
        
        return $formatted_products;
    }

    public function get_total_products() {
        $args = array(
            'status' => 'publish',
            'limit' => -1,
            'return' => 'ids',
        );
        
        $products = wc_get_products($args);
        return count($products);
    }
    
    public function test_lupa_connection() {
        $index_id = LupaSearch_Config::get_product_index_id();
        $api_key = LupaSearch_Config::get_api_key();

        if (empty($index_id)) {
            return array('success' => false, 'message' => 'Product Index ID is not configured');
        }

        if (empty($api_key)) {
            return array('success' => false, 'message' => 'API Key is not configured');
        }
        
        $url = "https://api.lupasearch.com/v1/indices/{$index_id}";
        $args = array(
            'headers' => array(
                'X-Lupa-Api-Key' => $api_key,
                'Content-Type' => 'application/json'
            )
        );
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code === 401) {
            return array('success' => false, 'message' => 'Invalid API key');
        }
        
        $indices = json_decode(wp_remote_retrieve_body($response), true);
        
        if (is_array($indices)) {
            $doc_indices = array_filter($indices, function($index) {
                return isset($index['type']) && $index['type'] === 'DOC';
            });
            
            return array(
                'success' => true,
                'indices' => array_map(function($index) {
                    return array(
                        'id' => $index['id'],
                        'name' => $index['name'],
                        'type' => $index['type'],
                        'isEnabled' => $index['isEnabled']
                    );
                }, $doc_indices),
                'indexed_products' => count($doc_indices)
            );
        }
        
        return array(
            'success' => false, 
            'message' => isset($indices['errors']['message']) ? $indices['errors']['message'] : 'Invalid response from LupaSearch API'
        );
    }

    private function get_product_categories($product) {
        $categories = array();
        $terms = get_the_terms($product->get_id(), 'product_cat');
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $categories[] = $term->name;
            }
        }
        return $categories;
    }

    private function get_product_images($product) {
        $images = array();
        $attachment_ids = $product->get_gallery_image_ids();
        foreach ($attachment_ids as $attachment_id) {
            $image_url = wp_get_attachment_image_url($attachment_id, 'full');
            if ($image_url) {
                $images[] = $image_url;
            }
        }
        return $images;
    }

    private function get_main_image_url($product) {
        $image_id = $product->get_image_id();
        if ($image_id) {
            $image_url = wp_get_attachment_image_url($image_id, 'full');
            return $image_url ? $image_url : '';
        }
        return '';
    }
}