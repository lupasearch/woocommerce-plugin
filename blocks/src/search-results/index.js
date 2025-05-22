import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

registerBlockType('lupasearch/search-results', {
    apiVersion: 3,
    // Title and category are typically defined in block.json
    // title: __('LupaSearch Results', 'lupasearch'), // Already in block.json
    icon: 'list-view',
    // category: 'widgets', // Already in block.json
    
    // Add supports property
    supports: {
        html: false,
        align: ['wide', 'full']
    },
    
    // Add attributes if needed
    attributes: {
        align: {
            type: 'string',
            default: 'wide'
        }
    },
    
    edit: function({ attributes }) {
        const blockProps = useBlockProps({
            className: attributes.align ? `align${attributes.align}` : ''
        });
        
        return (
            <div { ...blockProps }>
                <div className="lupasearch-editor-results" style={{ 
                    padding: '20px',
                    border: '1px dashed #ccc',
                    textAlign: 'center'
                }}>
                    {__('LupaSearch Results', 'lupasearch')}
                </div>
            </div>
        );
    },

    save: function() {
        return null; // Use server-side rendering
    }
});
