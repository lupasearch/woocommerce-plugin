import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';

registerBlockType('lupasearch/search-box', {
    apiVersion: 2,
    title: 'LupaSearch Box',
    icon: 'search',
    category: 'widgets',
    
    edit: function() {
        const blockProps = useBlockProps();
        
        return (
            <div { ...blockProps }>
                <div className="lupasearch-editor-box" style={{ 
                    padding: '20px',
                    border: '1px dashed #ccc',
                    textAlign: 'center'
                }}>
                    LupaSearch Box
                </div>
            </div>
        );
    },

    save: function() {
        return null; // Use server-side rendering
    }
});

// Front-end script execution
// Ensure this runs only on the front-end and when the DOM is ready.
// The `block.json` now includes this file in the "script" property.
if (typeof window !== 'undefined' && window.document) {
    document.addEventListener('DOMContentLoaded', function() {
        // Check if LupaSearch SDK is available and the target element exists
        // The #searchBox element is expected to be rendered by the PHP `render_search_box` function.
        if (window.lupaSearch && document.getElementById('searchBox')) {
            const lupaSearch = window.lupaSearch;

            const options = {
              inputSelector: "#searchBox", // Must match the ID in the server-rendered HTML
              minInputLength: 2,
              // ... Other options can be added here by the user
            };

            lupaSearch.searchBox(options);
            console.log('LupaSearch Box Initialized'); // For debugging
        } else {
            // Optional: Log if LupaSearch SDK or #searchBox element is not found on the front-end.
            // This helps in diagnosing issues if the main LupaSearch script isn't loaded
            // or if the server-side rendered HTML doesn't contain the #searchBox.
            // We avoid logging this in the WP editor context where #searchBox is not expected.
            if (!document.body.classList.contains('block-editor-page') && !document.body.classList.contains('wp-admin')) {
                if (!window.lupaSearch) {
                    console.warn('LupaSearch SDK (window.lupaSearch) not found on front-end.');
                }
                if (!document.getElementById('searchBox')) {
                    console.warn('Search box element with id="searchBox" not found on front-end.');
                }
            }
        }
    });
}
