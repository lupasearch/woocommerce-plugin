import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

registerBlockType('lupasearch/search-box', {
    apiVersion: 2,
    // Title and category are typically defined in block.json and will be translated from there.
    // We can remove them from here if they are in block.json to avoid duplication.
    // title: __('LupaSearch Box', 'lupasearch'), // Already in block.json
    icon: 'search',
    // category: 'widgets', // Already in block.json
    
    edit: function() {
        const blockProps = useBlockProps();
        
        return (
            <div { ...blockProps }>
                <div className="lupasearch-editor-box" style={{ 
                    padding: '20px',
                    border: '1px dashed #ccc',
                    textAlign: 'center'
                }}>
                    {__('LupaSearch Box', 'lupasearch')}
                </div>
            </div>
        );
    },

    save: function() {
        return null; // Use server-side rendering
    }
});
