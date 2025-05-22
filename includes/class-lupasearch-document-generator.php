<?php
if (!defined('ABSPATH')) {
    exit;
}

class LupaSearch_Document_Generator {
    private $product_provider;
    private $batch_size = 100;
    private $upload_dir;

    public function __construct() {
        $this->product_provider = new LupaSearch_Product_Provider();
        $this->upload_dir = wp_upload_dir();
    }

    public function generate_documents() {
        try {
            $total_products = $this->product_provider->get_total_products();
            $total_pages = ceil($total_products / $this->batch_size);
            $all_documents = array();

            for ($page = 1; $page <= $total_pages; $page++) {
                $products = $this->product_provider->get_products($page, $this->batch_size);
                $formatted_products = $this->format_products($products);
                $all_documents = array_merge($all_documents, $formatted_products);
            }

            // Generate unique filename
            $filename = 'lupasearch-products-' . gmdate('Y-m-d-His') . '.json';
            $filepath = $this->upload_dir['path'] . '/' . $filename;
            
            // Save JSON file
            $json_content = json_encode($all_documents, JSON_PRETTY_PRINT);
            if (file_put_contents($filepath, $json_content) === false) {
                throw new Exception('Failed to save JSON file');
            }

            return array(
                'success' => true,
                'message' => 'Successfully generated ' . count($all_documents) . ' documents',
                'data' => $all_documents,
                'filename' => $filename,
                'filepath' => $this->upload_dir['url'] . '/' . $filename
            );

        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Error generating documents: ' . $e->getMessage()
            );
        }
    }

    public function import_documents_via_api($documents) {
        $index_id = LupaSearch_Config::get_product_index_id();
        $api_key = LupaSearch_Config::get_api_key();

        if (empty($index_id) || empty($api_key)) {
            return array(
                'success' => false,
                'message' => 'Missing configuration: Index ID or API Key'
            );
        }

        $url = "https://api.lupasearch.com/v1/indices/{$index_id}/documents";
        
        // Split documents into batches to stay under 10MB limit
        $batches = array_chunk($documents, 100);
        $batch_results = array();
        
        foreach ($batches as $batch) {
            $response = wp_remote_post($url, array(
                'headers' => array(
                    'X-Lupa-Api-Key' => $api_key,
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode(array('documents' => $batch)),
                'timeout' => 30
            ));

            if (is_wp_error($response)) {
                return array(
                    'success' => false,
                    'message' => 'API Error: ' . $response->get_error_message()
                );
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if ($status_code !== 200 || !isset($body['success'])) {
                return array(
                    'success' => false,
                    'message' => isset($body['errors']['message']) ? $body['errors']['message'] : 'Unknown API error'
                );
            }

            $batch_results[] = $body;
        }

        return array(
            'success' => true,
            'message' => 'Successfully imported ' . count($documents) . ' documents',
            'batch_results' => $batch_results
        );
    }

    private function format_products($products_data_from_provider) { // Renamed parameter
        $formatted_documents = array();

        foreach ($products_data_from_provider as $product_data) {
            $document = $this->format_common_product_data($product_data);
            $product_type = $product_data['product_type'] ?? 'simple'; // Default if not set

            $document['type'] = $product_type; // LupaSearch type field

            if ($product_type === 'parent') {
                // Parent specific fields already set in format_common_product_data
                // Price for parent: use its own price fields which might be min variation price
                // Stock for parent: can be an aggregation or based on its own settings
                $document['is_group_head'] = true; // Example field to identify parent
            } elseif ($product_type === 'variation') {
                $document['parent_id'] = $product_data['parent_id'];
                $document['sku'] = $product_data['sku'] ?? '';
                // Variation attributes (flattened)
                if (!empty($product_data['variation_attributes_raw']) && is_array($product_data['variation_attributes_raw'])) {
                    foreach ($product_data['variation_attributes_raw'] as $taxonomy => $term_slug) {
                        $attribute_slug = str_replace('attribute_', '', $taxonomy); // pa_color -> color
                        $attribute_slug = str_replace('pa_', '', $attribute_slug); // pa_color -> color
                        
                        $term = get_term_by('slug', $term_slug, $taxonomy);
                        if ($term && !is_wp_error($term)) {
                            $document[$attribute_slug] = $term->name;
                        } else {
                            // Fallback if term name not found, use slug (might need better handling)
                            $document[$attribute_slug] = $term_slug;
                        }
                    }
                }
            }
            // For 'simple' type, common data is usually enough.

            $formatted_documents[] = $document;
        }
        return $formatted_documents;
    }

    // New helper method for common data formatting
    private function format_common_product_data(array $product_data) {
        $regular_price_raw = $product_data['price'] ?? null;
        $sale_price_raw = $product_data['final_price'] ?? null;

        $price_val = !empty($regular_price_raw) ? (float) $regular_price_raw : null;
        $final_price_val = !empty($sale_price_raw) ? (float) $sale_price_raw : $price_val;
        if (is_null($final_price_val) && !is_null($price_val)) {
            $final_price_val = $price_val;
        }

        // Base document structure
        $document = array(
            'id'                => $product_data['id'],
            'title'             => $product_data['title'], // For variations, this is parent's title
            'description'       => $product_data['description'] ?? '',
            'description_short' => $product_data['description_short'] ?? '',
            'price'             => $price_val,
            'final_price'       => $final_price_val,
            'categories'        => $product_data['category_names'] ?? [],
            'category_ids'      => $product_data['category_ids'] ?? [],
            'image'             => $product_data['main_image'] ?? '', // Main image (variation or parent)
            'images'            => $product_data['images'] ?? [],     // Gallery (parent's gallery for variations)
            'url'               => $product_data['url'],
            'visibility'        => $product_data['visibility'] ?? 'visible',
            'qty'               => isset($product_data['stock_quantity']) ? (int) $product_data['stock_quantity'] : 0,
            'instock'           => (bool) ($product_data['is_in_stock'] ?? false),
            'rating'            => $this->get_product_rating($product_data['id']),
            'tags'              => $product_data['tags'] ?? [],
            'sku'               => $product_data['sku'] ?? '', // SKU for simple/parent, variation SKU handled below
        );
        return $document;
    }


    public function format_single_product_from_data(array $product_data_from_provider) {
        if (empty($product_data_from_provider) || !isset($product_data_from_provider['id'])) {
            return null; 
        }
        
        // This method now expects a single product data array from the provider
        // It will be one item from the array that get_products() or get_data_for_single_product() (for cron) returns
        
        $document = $this->format_common_product_data($product_data_from_provider);
        $product_type = $product_data_from_provider['product_type'] ?? 'simple';

        $document['type'] = $product_type;

        if ($product_type === 'parent') {
            $document['is_group_head'] = true;
        } elseif ($product_type === 'variation') {
            $document['parent_id'] = $product_data_from_provider['parent_id'];
            // SKU is already set by format_common_product_data if available at top level from provider
            // If variation_attributes_raw is set, process it
            if (!empty($product_data_from_provider['variation_attributes_raw']) && is_array($product_data_from_provider['variation_attributes_raw'])) {
                foreach ($product_data_from_provider['variation_attributes_raw'] as $taxonomy => $term_slug) {
                    $attribute_slug = str_replace('attribute_', '', $taxonomy);
                    $attribute_slug = str_replace('pa_', '', $attribute_slug);
                    
                    $term = get_term_by('slug', $term_slug, $taxonomy);
                    if ($term && !is_wp_error($term)) {
                        $document[$attribute_slug] = $term->name;
                    } else {
                        $document[$attribute_slug] = $term_slug;
                    }
                }
            }
        }
        
        return $document;
    }

    private function get_product_attribute($product, $attribute_name) {
        // $product here is expected to be the raw product data array from the provider
        // which contains an 'id' key.
        $product_id = isset($product['id']) ? $product['id'] : null;
        if (!$product_id) {
            return ''; // Or handle error appropriately
        }
        $product_obj = wc_get_product($product_id);
        if (!$product_obj) {
            return '';
        }
        
        $attribute = $product_obj->get_attribute($attribute_name);
        return $attribute ? $attribute : '';
    }

    private function get_product_rating($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return 0;
        }

        $rating = $product->get_average_rating();
        return $rating ? (float) $rating : 0;
    }
}
