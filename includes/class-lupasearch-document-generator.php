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
            $formatted_product = array(
                'id' => $product['id'],
                'name' => $product['title'],
                'brand' => '', // Add brand if available in your WooCommerce setup
                'color' => $this->get_product_attribute($product, 'color'),
                'image' => $product['main_image'],
                'price' => (float) $product['final_price'],
                'author' => '', // Add author if applicable
                'gender' => $this->get_product_attribute($product, 'gender'),
                'rating' => $this->get_product_rating($product['id']),
                'category' => implode(', ', $product['categories']),
                'description' => $product['description'],
                'alternativeImages' => $product['images'],
                'url' => $product['url'] // Add the product link
            );

            $formatted_products[] = $formatted_product;
        }

        return $formatted_products;
    }

    private function get_product_attribute($product, $attribute_name) {
        $product_obj = wc_get_product($product['id']);
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
