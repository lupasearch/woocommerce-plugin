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
            $filename = 'lupasearch-products-' . date('Y-m-d-His') . '.json';
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

    private function format_products($products) {
        $formatted_products = array();

        foreach ($products as $product) {
            // Price logic:
            // 'price' is the regular price.
            // 'final_price' is the sale price, or regular price if not on sale.
            $regular_price_raw = $product['price']; // From LupaSearch_Product_Provider
            $sale_price_raw = $product['final_price']; // From LupaSearch_Product_Provider, this is WC's get_price()

            $price_val = !empty($regular_price_raw) ? (float) $regular_price_raw : null;
            // If sale_price_raw is empty OR if it's the same as regular_price_raw (meaning no specific sale price is set, WC get_price() returns regular)
            // then final_price should be the same as price_val.
            // If sale_price_raw is different and not empty, it's the actual final price.
            $final_price_val = !empty($sale_price_raw) ? (float) $sale_price_raw : $price_val;
            // Ensure final_price is not null if price_val is set
            if (is_null($final_price_val) && !is_null($price_val)) {
                $final_price_val = $price_val;
            }


            $formatted_product = array(
                'id' => $product['id'],
                'visibility' => $product['visibility'],
                'description' => $product['description'],
                'description_short' => $product['description_short'],
                'name' => $product['title'], // 'name' comes from 'title' in provider
                'price' => $price_val,
                'final_price' => $final_price_val,
                'categories' => $product['category_names'] ?? [], // 'categories' are names
                'category_ids' => $product['category_ids'] ?? [],
                'images' => $product['images'] ?? [], // 'images' are gallery images
                'image' => $product['main_image'] ?? '', // Renamed from main_image
                'url' => $product['url'],
                'qty' => isset($product['stock_quantity']) ? (int) $product['stock_quantity'] : 0,
                'instock' => (bool) $product['is_in_stock'],
                // Retain other fields if they are still relevant or add them as needed
                // For example, rating and other attributes:
                'rating' => $this->get_product_rating($product['id']),
                // 'brand' => $this->get_product_attribute($product, 'brand'), // Example
                // 'color' => $this->get_product_attribute($product, 'color'), // Example
            );

            // Clean up any null values to avoid issues with LupaSearch indexing if it's strict
            // $formatted_product = array_filter($formatted_product, function($value) {
            //     return !is_null($value);
            // });

            $formatted_products[] = $formatted_product;
        }

        return $formatted_products;
    }

    public function format_single_product_from_data(array $product_data_from_provider) {
        if (empty($product_data_from_provider) || !isset($product_data_from_provider['id'])) {
            // Or throw an exception, or return an error structure
            return null; 
        }
        
        // Reuse the logic from format_products for a single item
        // Price logic:
        $regular_price_raw = $product_data_from_provider['price'];
        $sale_price_raw = $product_data_from_provider['final_price'];

        $price_val = !empty($regular_price_raw) ? (float) $regular_price_raw : null;
        $final_price_val = !empty($sale_price_raw) ? (float) $sale_price_raw : $price_val;
        if (is_null($final_price_val) && !is_null($price_val)) {
            $final_price_val = $price_val;
        }

        $formatted_product = array(
            'id' => $product_data_from_provider['id'],
            'visibility' => $product_data_from_provider['visibility'],
            'description' => $product_data_from_provider['description'],
            'description_short' => $product_data_from_provider['description_short'],
            'name' => $product_data_from_provider['title'], // 'name' comes from 'title' in provider
            'price' => $price_val,
            'final_price' => $final_price_val,
            'categories' => $product_data_from_provider['category_names'] ?? [],
            'category_ids' => $product_data_from_provider['category_ids'] ?? [],
            'images' => $product_data_from_provider['images'] ?? [],
            'image' => $product_data_from_provider['main_image'] ?? '', // Renamed from main_image
            'url' => $product_data_from_provider['url'],
            'qty' => isset($product_data_from_provider['stock_quantity']) ? (int) $product_data_from_provider['stock_quantity'] : 0,
            'instock' => (bool) $product_data_from_provider['is_in_stock'],
            'rating' => $this->get_product_rating($product_data_from_provider['id']),
        );
        
        return $formatted_product;
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
