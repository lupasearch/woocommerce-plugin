<?php
if (!defined('ABSPATH')) {
    exit;
}

class LupaSearch_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct(
            'lupasearch_widget',
            __('LupaSearch Box', 'lupasearch-woo'),
            array('description' => __('Adds LupaSearch search box to your site', 'lupasearch-woo'))
        );
    }

    public function widget($args, $instance) {
        $title = !empty($instance['title']) ? apply_filters('widget_title', $instance['title']) : '';
        
        echo wp_kses_post($args['before_widget']);
        if (!empty($title)) {
            echo wp_kses_post($args['before_title']) . esc_html($title) . wp_kses_post($args['after_title']);
        }
        echo '<div><div id="searchBox"></div></div>'; // This line is fine as it's static HTML
        echo wp_kses_post($args['after_widget']);
    }

    public function form($instance) {
        $title = !empty($instance['title']) ? $instance['title'] : '';
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php esc_html_e('Title:', 'lupasearch-woo'); ?></label>
            <input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" 
                   name="<?php echo esc_attr($this->get_field_name('title')); ?>" 
                   type="text" value="<?php echo esc_attr($title); ?>">
        </p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        $instance = array();
        $instance['title'] = (!empty($new_instance['title'])) 
            ? wp_strip_all_tags($new_instance['title']) 
            : '';
        return $instance;
    }
}
