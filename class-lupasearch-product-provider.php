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
            $category_data = $this->get_product_categories_data($product);
            $formatted_products[] = array(
                'id' => $product->get_id(),
                'title' => $product->get_name(),
                'description' => $product->get_description(),
                'description_short' => $product->get_short_description(),
                'price' => $product->get_regular_price(),
                'final_price' => $product->get_price(),
                'category_names' => $category_data['names'],
                'category_ids' => $category_data['ids'],
                'images' => $this->get_product_images($product),
                'main_image' => $this->get_main_image_url($product),
                'url' => $product->get_permalink(),
                'visibility' => $product->get_catalog_visibility(),
                'stock_quantity' => $product->get_stock_quantity(),
                'is_in_stock' => $product->is_in_stock(),
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

    public function get_data_for_single_product(WC_Product $product_object) {
        if (!$product_object) {
            return null;
        }

        $category_data = $this->get_product_categories_data($product_object); // Pass WC_Product object

        return array(
            'id' => $product_object->get_id(),
            'title' => $product_object->get_name(),
            'description' => $product_object->get_description(),
            'description_short' => $product_object->get_short_description(),
            'price' => $product_object->get_regular_price(),
            'final_price' => $product_object->get_price(),
            'category_names' => $category_data['names'],
            'category_ids' => $category_data['ids'],
            'images' => $this->get_product_images($product_object), // Pass WC_Product object
            'main_image' => $this->get_main_image_url($product_object), // Pass WC_Product object
            'url' => $product_object->get_permalink(),
            'visibility' => $product_object->get_catalog_visibility(),
            'stock_quantity' => $product_object->get_stock_quantity(),
            'is_in_stock' => $product_object->is_in_stock(),
        );
    }

    private function get_product_categories_data($product) { // Parameter can remain $product as it's WC_Product
        $categories_data = array('ids' => array(), 'names' => array());
        $terms = get_the_terms($product->get_id(), 'product_cat');
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $categories_data['ids'][] = $term->term_id;
                $categories_data['names'][] = $term->name;
            }
        }
        return $categories_data;
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
