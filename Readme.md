# LupaSearch for WooCommerce

**Contributors:** (Your Name/Company), LupaSearch Team
**Tags:** woocommerce, search, product search, faceted search, woocommerce search, instant search, lupa, lupasearch
**Requires at least:** 5.0
**Tested up to:** 6.5
**Stable tag:** 1.0.0
**License:** GPLv2 or later
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

Supercharge your WooCommerce store's search functionality with LupaSearch. Provide your customers with a fast, relevant, and intuitive search experience.

## Description

LupaSearch is a powerful, AI-driven search solution designed to help your customers find exactly what they're looking for, quickly and easily. This plugin seamlessly integrates LupaSearch with your WooCommerce store, replacing the default search with a feature-rich, customizable search interface.

**Key Features:**

*   **Lightning-Fast Search:** Delivers search results instantly.
*   **AI-Powered Relevance:** Understands user intent to provide the most relevant results.
*   **Typo Tolerance:** Handles typos and misspellings gracefully.
*   **Synonyms & Autocomplete:** Suggests relevant terms and completes queries as users type.
*   **Faceted Navigation:** Allows users to filter search results by attributes, price, categories, and more.
*   **Customizable Search UI:** Tailor the look and feel of your search results page.
*   **WooCommerce Integration:** Works seamlessly with your products, categories, and attributes.
*   **Search Analytics:** Gain insights into what your customers are searching for.
*   **Widget & Shortcode Support:** Easily add the LupaSearch box to sidebars and other areas.
*   **Gutenberg Blocks:** Dedicated blocks for adding a search box and displaying search results.
*   **404 Page Enhancement:** Replaces the default WordPress/WooCommerce search on 404 (Not Found) pages with the LupaSearch box for a better user experience.

## Installation

1.  **Download & Upload:**
    *   Download the plugin ZIP file.
    *   Log in to your WordPress admin area.
    *   Navigate to **Plugins > Add New**.
    *   Click **Upload Plugin** and choose the downloaded ZIP file.
    *   Click **Install Now**.
2.  **Activate:**
    *   After installation, click **Activate Plugin**.
3.  **Configure:**
    *   Go to **WooCommerce > LupaSearch** in your WordPress admin menu.
    *   Enter your LupaSearch **UI Plugin Key**. You can find this in your LupaSearch dashboard.
    *   Configure other settings as needed, such as overriding the default WordPress search.

## Frequently Asked Questions

**Q: How do I get a LupaSearch UI Plugin Key?**
A: You need to sign up for a LupaSearch account at [lupasearch.com](https://lupasearch.com). Your UI Plugin Key will be available in your LupaSearch dashboard.

**Q: How do I add the LupaSearch box to my theme's sidebar?**
A:
1.  Go to **Appearance > Widgets**.
2.  Find the "LupaSearch Box" widget.
3.  Drag and drop it into your desired sidebar or widget area.
4.  Optionally, give it a title.

**Q: Can I use a shortcode to display the LupaSearch box?**
A: Yes, while this plugin primarily focuses on automatic integration and widgets, the core LupaSearch functionality is initialized on a `div` with the ID `searchBox`. You can manually add `<div id="searchBox"></div>` to any page or post content where you want the search box to appear, provided the LupaSearch scripts are loaded on that page. The plugin ensures scripts are loaded globally if the LupaSearch integration is active.

**Q: How does the 404 page search override work?**
A: If a user lands on a 404 (Not Found) page, this plugin will:
1.  Attempt to replace the theme's standard search form (if it uses `get_search_form()`) with the LupaSearch box.
2.  Hide the default WooCommerce product search widget if it's present on the 404 page.
This provides a more helpful search experience directly on the 404 page, powered by LupaSearch.

**Q: Are LupaSearch Gutenberg blocks available?**
A: Yes, the plugin includes two Gutenberg blocks:
    *   **LupaSearch Box:** Adds a LupaSearch search input field.
    *   **LupaSearch Results:** Designates an area for LupaSearch results to be displayed (typically used on a dedicated search results page).

**Q: Where can I find more detailed documentation?**
A: For more detailed setup and customization options, please refer to the official LupaSearch documentation available on the LupaSearch website.

## Building for Production (For Developers)

If you have cloned the plugin repository or are making code changes and need to generate a production-ready `.zip` file, follow these steps:

1.  **Prerequisites:**
    *   Ensure you have Node.js and npm installed on your system.

2.  **Navigate to Plugin Directory:**
    Open your terminal and navigate to the root directory of the plugin (e.g., `cd path/to/lupasearch-wordpress`).

3.  **Install Dependencies:**
    Run the following command to install the necessary development dependencies:
    ```bash
    npm install
    ```

4.  **Build Assets:**
    Compile and minify JavaScript and CSS assets for production:
    ```bash
    npm run build
    ```

5.  **Create Plugin ZIP:**
    Package the plugin into a distributable `.zip` file. This command will create a ZIP file in the plugin's root directory, ready for upload.
    ```bash
    npm run plugin-zip
    ```
    The generated ZIP file will typically be named after the plugin's directory (e.g., `lupasearch-wordpress.zip`).

## Screenshots

*(Consider adding screenshots here if possible, e.g., LupaSearch admin settings, search box widget, search results page)*

1.  *LupaSearch Admin Configuration Page*
2.  *LupaSearch Box Widget in a Sidebar*
3.  *Example of LupaSearch Results Page*

## Changelog

**1.0.0 - YYYY-MM-DD**
*   Initial release.
*   Integration with LupaSearch API.
*   Override default WordPress search option.
*   LupaSearch Box widget.
*   LupaSearch Box and LupaSearch Results Gutenberg blocks.
*   Enhanced 404 page search: Replaces default/WooCommerce search with LupaSearch box.

*(Add more versions as they are released)*

## Upgrade Notice

**1.0.0**
Initial release of the LupaSearch plugin for WooCommerce.
