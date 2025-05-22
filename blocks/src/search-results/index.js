import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';

registerBlockType('lupasearch/search-results', {
    apiVersion: 3,
    title: 'LupaSearch Results',
    icon: 'list-view',
    category: 'widgets',
    
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
                    LupaSearch Results
                </div>
            </div>
        );
    },

    save: function() {
        return null; // Use server-side rendering
    }
});
