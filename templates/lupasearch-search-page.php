<?php
/**
 * The template for displaying LupaSearch results.
 *
 * @package LupaSearch
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

get_header(); 
?>

<div id="primary" class="content-area">
    <main id="main" class="site-main" role="main">
        <?php
        // Output LupaSearch search box and results containers
        if (class_exists('LupaSearch_Blocks')) {
            $blocks_instance = new LupaSearch_Blocks();
            echo wp_kses_post($blocks_instance->render_search_box());
            echo '<div style="margin-bottom: 20px;"></div>';
            echo wp_kses_post($blocks_instance->render_search_results());
        } else {
            // Fallback if the class isn't loaded
            echo '<div><div id="searchBox"></div></div>';
            echo '<div style="margin-bottom: 20px;"></div>';
            echo '<div><div id="searchResults"></div></div>';
        }
        ?>
    </main><!-- #main -->
</div><!-- #primary -->

<?php
get_sidebar(); // Optional: include if your theme uses a sidebar on search pages
get_footer();
?>
