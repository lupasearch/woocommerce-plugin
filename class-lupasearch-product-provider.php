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
        
        foreach ($products as $product_obj) { // Renamed to $product_obj for clarity
            if ($product_obj->is_type('variable')) {
                // 1. Add Parent Variable Product
                $category_data = $this->get_product_categories_data($product_obj);
                $parent_data = array(
                    'id' => $product_obj->get_id(),
                    'product_type' => 'parent', // Mark as parent
                    'title' => $product_obj->get_name(),
                    'description' => $product_obj->get_description(),
                    'description_short' => $product_obj->get_short_description(),
                    'price' => $product_obj->get_regular_price(), // Or specific logic for parent price
                    'final_price' => $product_obj->get_price(),      // Or specific logic for parent price
                    'category_names' => $category_data['names'],
                    'category_ids' => $category_data['ids'],
                    'images' => $this->get_product_images($product_obj), // Parent gallery
                    'main_image' => $this->get_main_image_url($product_obj), // Parent main image
                    'url' => $product_obj->get_permalink(),
                    'visibility' => $product_obj->get_catalog_visibility(),
                    // Stock for parent might be an aggregation or based on children.
                    // For now, using parent's direct stock status if it manages stock itself, or true if any child is in stock.
                    'stock_quantity' => $product_obj->get_stock_quantity(), 
                    'is_in_stock' => $product_obj->is_in_stock(), 
                    'tags' => $this->get_product_tags_data($product_obj), // Get parent tags
                     // Add other parent-specific fields as needed
                );
                $formatted_products[] = $parent_data;

                // 2. Add Variations
                $variations = $product_obj->get_children();
                foreach ($variations as $variation_id) {
                    $variation_obj = wc_get_product($variation_id);
                    // Check if variation is valid and has 'publish' status
                    if ($variation_obj && $variation_obj->exists() && $variation_obj->get_status() === 'publish') { 
                        // Inherited data (can be fetched once for parent and passed or re-fetched if simpler)
                        // For now, some data like categories, parent images are implicitly available via parent_data for generator
                        
                        $variation_specific_image_url = $this->get_main_image_url($variation_obj); // Variation specific image

                        $formatted_products[] = array(
                            'id' => $variation_obj->get_id(),
                            'parent_id' => $product_obj->get_id(),
                            'product_type' => 'variation',
                            'title' => $variation_obj->get_name(), // Use variation's full descriptive name
                            'sku' => $variation_obj->get_sku(),
                            'price' => $variation_obj->get_regular_price(),
                            'final_price' => $variation_obj->get_price(),
                            'stock_quantity' => $variation_obj->get_stock_quantity(),
                            'is_in_stock' => $variation_obj->is_in_stock(),
                            'main_image' => !empty($variation_specific_image_url) ? $variation_specific_image_url : $parent_data['main_image'], // Variation image or parent's
                            'images' => $parent_data['images'], // Parent's gallery for variation's gallery field
                            'url' => $variation_obj->get_permalink(),
                            'variation_attributes_raw' => $this->get_variation_attributes_raw($variation_obj),
                            // Inherited fields that DocumentGenerator will use by referencing parent_id or if passed directly
                            'description' => $parent_data['description'],
                            'description_short' => $parent_data['description_short'],
                            'category_names' => $parent_data['category_names'],
                            'category_ids' => $parent_data['category_ids'],
                            'visibility' => $parent_data['visibility'], // Usually parent's visibility applies
                            'tags' => $parent_data['tags'],
                        );
                    }
                }
            } elseif ($product_obj->is_type('simple')) { // Or other types like 'external', 'grouped' if supported
                $category_data = $this->get_product_categories_data($product_obj);
                $formatted_products[] = array(
                    'id' => $product_obj->get_id(),
                    'product_type' => 'simple',
                    'title' => $product_obj->get_name(),
                    'description' => $product_obj->get_description(),
                    'description_short' => $product_obj->get_short_description(),
                    'price' => $product_obj->get_regular_price(),
                    'final_price' => $product_obj->get_price(),
                    'category_names' => $category_data['names'],
                    'category_ids' => $category_data['ids'],
                    'images' => $this->get_product_images($product_obj),
                    'main_image' => $this->get_main_image_url($product_obj),
                    'url' => $product_obj->get_permalink(),
                    'visibility' => $product_obj->get_catalog_visibility(),
                    'stock_quantity' => $product_obj->get_stock_quantity(),
                    'is_in_stock' => $product_obj->is_in_stock(),
                    'tags' => $this->get_product_tags_data($product_obj),
                );
            }
            // Add handling for other product types if necessary
        }
        
        return $formatted_products;
    }

    public function get_total_products() {
        $total_documents = 0;
        $page = 1;
        $limit = 100; // Batch size for fetching products to count

        do {
            $args = array(
                'status' => 'publish', // Consider if other statuses should be counted if they are indexed
                'limit'  => $limit,
                'page'   => $page,
                'return' => 'objects', // Need objects to check type and get children
            );
            $products = wc_get_products($args);

            if (empty($products)) {
                break;
            }

            foreach ($products as $product) {
                if ($product->is_type('variable')) {
                    $total_documents++; // Count the parent variable product
                    $variations = $product->get_children();
                    foreach ($variations as $variation_id) {
                        $variation_obj = wc_get_product($variation_id);
                        // Count only if variation is valid and has 'publish' status
                        if ($variation_obj && $variation_obj->exists() && $variation_obj->get_status() === 'publish') {
                            $total_documents++;
                        }
                    }
                } elseif ($product->is_type('simple')) { // Add other types if they are indexed
                    $total_documents++;
                }
                // Add other product types if they are also indexed individually
            }
            $page++;
        } while (count($products) === $limit);
        
        return $total_documents;
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
            'product_type' => $product_object->is_type('variation') ? 'variation' : 'simple', // Default to simple, parent handled below
            'parent_id' => $product_object->is_type('variation') ? $product_object->get_parent_id() : null,
            'variation_attributes_raw' => $product_object->is_type('variation') ? $this->get_variation_attributes_raw($product_object) : null,
            'tags' => $this->get_product_tags_data($product_object),
        );

        // If the product_object is a variable parent, we need to return its data + all its variations' data
        if ($product_object->is_type('variable')) {
            $all_docs_data = [];
            // Parent data
            $parent_base_data = $single_product_data; // Start with what we have
            $parent_base_data['product_type'] = 'parent';
            // Ensure parent-specific fields are correctly set if they differ from the general extraction
            $parent_base_data['price'] = $product_object->get_regular_price(); 
            $parent_base_data['final_price'] = $product_object->get_price();
            // Remove variation-specific fields from parent
            unset($parent_base_data['parent_id']);
            unset($parent_base_data['variation_attributes_raw']);
            $all_docs_data[] = $parent_base_data;

            // Variations data
            $variations = $product_object->get_children();
            foreach ($variations as $variation_id) {
                $variation_obj = wc_get_product($variation_id);
                if ($variation_obj && $variation_obj->exists() && $variation_obj->get_status() === 'publish') {
                    $variation_category_data = $this->get_product_categories_data($product_object); // Categories from parent
                    $variation_tags_data = $this->get_product_tags_data($product_object); // Tags from parent
                    $variation_specific_image_url = $this->get_main_image_url($variation_obj);
                    
                    $all_docs_data[] = array(
                        'id' => $variation_obj->get_id(),
                        'parent_id' => $product_object->get_id(),
                        'product_type' => 'variation',
                        'title' => $variation_obj->get_name(), // Use variation's full descriptive name
                        'description' => $product_object->get_description(), // Parent's desc
                        'description_short' => $product_object->get_short_description(), // Parent's short desc
                        'price' => $variation_obj->get_regular_price(),
                        'final_price' => $variation_obj->get_price(),
                        'category_names' => $variation_category_data['names'],
                        'category_ids' => $variation_category_data['ids'],
                        'images' => $this->get_product_images($product_object), // Parent's gallery
                        'main_image' => !empty($variation_specific_image_url) ? $variation_specific_image_url : $this->get_main_image_url($product_object),
                        'url' => $variation_obj->get_permalink(),
                        'visibility' => $product_object->get_catalog_visibility(), // Parent's visibility
                        'stock_quantity' => $variation_obj->get_stock_quantity(),
                        'is_in_stock' => $variation_obj->is_in_stock(),
                        'variation_attributes_raw' => $this->get_variation_attributes_raw($variation_obj),
                        'sku' => $variation_obj->get_sku(),
                        'tags' => $variation_tags_data,
                    );
                }
            }
            return $all_docs_data;
        }

        // For simple or single variation, return the single data array
        return array($single_product_data); // Always return an array of arrays for consistency with sync logic
    }

    private function get_variation_attributes_raw(WC_Product_Variation $variation) {
        return $variation->get_variation_attributes(); // Returns array like array( 'attribute_pa_color' => 'blue', ... )
    }
    
    private function get_product_tags_data(WC_Product $product) {
        $tags = array();
        $terms = get_the_terms($product->get_id(), 'product_tag');
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $tags[] = $term->name;
            }
        }
        return $tags;
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
