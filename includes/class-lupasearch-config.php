<?php
if (!defined('ABSPATH')) {
    exit;
}

class LupaSearch_Config {
    const OPTION_UI_PLUGIN_KEY = 'lupasearch_ui_plugin_key';
    const OPTION_PRODUCT_INDEX_ID = 'lupasearch_product_index_id';
    const OPTION_API_KEY = 'lupasearch_api_key';
    const OPTION_ORGANIZATION = 'lupasearch_organization';
    const OPTION_PROJECT = 'lupasearch_project';
    
    public static function get_ui_plugin_key() {
        return get_option(self::OPTION_UI_PLUGIN_KEY, '');
    }
    
    public static function get_product_index_id() {
        return get_option(self::OPTION_PRODUCT_INDEX_ID, '');
    }

    public static function get_api_key() {
        return get_option(self::OPTION_API_KEY, '');
    }

    public static function get_organization() {
        return get_option(self::OPTION_ORGANIZATION, '');
    }

    public static function get_project() {
        return get_option(self::OPTION_PROJECT, '');
    }
}