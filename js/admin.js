jQuery(document).ready(function($) {
    const { __ } = wp.i18n;

    $('#test-connection').on('click', function() {
        const button = $(this);
        const statusEl = $('#connection-status');
        const detailsEl = $('#connection-details');
        
        button.prop('disabled', true);
        statusEl.html(__('Testing connection...', 'lupasearch-woo'));
        
        $.ajax({
            url: lupaSearchAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'test_lupa_connection',
                nonce: lupaSearchAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    statusEl.html('<span style="color: green;">' + __('✓ Connected successfully', 'lupasearch-woo') + '</span>');
                    detailsEl.show();
                    
                    // Update stats
                    // $('#indexed-products').text(response.indexed_products || 0);
                    // $('#active-indices').text(response.indices.length);
                    
                    // Render indices list
                    const indicesList = response.indices.map(index => `
                        <div class="index-item">
                            <span>${index.name} (${index.id})</span>
                            <span class="index-status ${index.isEnabled ? 'active' : 'inactive'}">
                                ${index.isEnabled ? __('Active', 'lupasearch-woo') : __('Inactive', 'lupasearch-woo')}
                            </span>
                        </div>
                    `).join('');
                    
                    $('#available-indices').html(indicesList);
                } else {
                    statusEl.html('<span style="color: red;">✕ ' + (response.message || __('Connection failed', 'lupasearch-woo')) + '</span>');
                    detailsEl.hide();
                }
            },
            error: function() {
                statusEl.html('<span style="color: red;">✕ ' + __('Connection failed', 'lupasearch-woo') + '</span>');
                detailsEl.hide();
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });

    $('#generate-documents').on('click', function() {
        const button = $(this);
        const statusSpan = $('#generation-status');
        
        button.prop('disabled', true);
        statusSpan.html(__('Generating documents...', 'lupasearch-woo'));
        
        $.ajax({
            url: lupaSearchAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'generate_lupasearch_documents',
                nonce: lupaSearchAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    statusSpan.html('<span style="color: green;">' + __('Documents generated successfully!', 'lupasearch-woo') + '</span>');
                    downloadJSON(response.data, response.filename);
                } else {
                    statusSpan.html('<span style="color: red;">' + __('Generation failed:', 'lupasearch-woo') + ' ' + response.message + '</span>');
                }
            },
            error: function() {
                statusSpan.html('<span style="color: red;">' + __('Generation failed: Network error', 'lupasearch-woo') + '</span>');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });

    $('#import-documents').on('click', function() {
        const button = $(this);
        const statusSpan = $('#generation-status');
        
        if (!confirm(__('Are you sure you want to import documents to LupaSearch?', 'lupasearch-woo'))) {
            return;
        }
        
        button.prop('disabled', true);
        statusSpan.html(__('Importing documents to LupaSearch...', 'lupasearch-woo'));
        
        $.ajax({
            url: lupaSearchAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'import_lupasearch_documents',
                nonce: lupaSearchAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    statusSpan.html('<span style="color: green;">' + response.message + '</span>'); // Assuming response.message is already translated or a key
                } else {
                    statusSpan.html('<span style="color: red;">' + __('Import failed:', 'lupasearch-woo') + ' ' + response.message + '</span>');
                }
            },
            error: function() {
                statusSpan.html('<span style="color: red;">' + __('Import failed: Network error', 'lupasearch-woo') + '</span>');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });

    $('#reindex-all').on('click', function() {
        const button = $(this);
        const statusSpan = $('#reindex-status');
        
        if (!confirm(__('Are you sure you want to reindex all products? This may take a while.', 'lupasearch-woo'))) {
            return;
        }
        
        button.prop('disabled', true);
        statusSpan.html(__('Reindexing all products...', 'lupasearch-woo'));
        
        $.ajax({
            url: lupaSearchAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'reindex_lupasearch_products',
                nonce: lupaSearchAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    statusSpan.html('<span style="color: green;">' + response.message + '</span>'); // Assuming response.message is already translated or a key
                    setTimeout(() => location.reload(), 2000);
                } else {
                    statusSpan.html('<span style="color: red;">' + __('Reindex failed:', 'lupasearch-woo') + ' ' + response.message + '</span>');
                }
            },
            error: function() {
                statusSpan.html('<span style="color: red;">' + __('Reindex failed: Network error', 'lupasearch-woo') + '</span>');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });

    function downloadJSON(data, filename) {
        const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.style.display = 'none';
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
    }

    // Clear LupaSearch Logs
    $('#clear-lupasearch-logs').on('click', function() {
        const button = $(this);
        
        if (!confirm(__('Are you sure you want to clear all LupaSearch activity logs? This action cannot be undone.', 'lupasearch-woo'))) {
            return;
        }
        
        button.prop('disabled', true);
        // Optionally, add a status message element near the button or logs table
        // For now, we'll rely on an alert and page reload.

        $.ajax({
            url: lupaSearchAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'clear_lupasearch_logs',
                nonce: lupaSearchAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.data.message || __('Logs cleared successfully.', 'lupasearch-woo'));
                    location.reload(); // Reload the page to show empty logs table
                } else {
                    alert(__('Failed to clear logs:', 'lupasearch-woo') + ' ' + (response.data.message || __('Unknown error', 'lupasearch-woo')));
                }
            },
            error: function() {
                alert(__('Failed to clear logs: Network error or unauthorized.', 'lupasearch-woo'));
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });

    // Delete All Documents & Reindex
    $('#delete-all-and-reindex').on('click', function() {
        const button = $(this);
        const statusSpan = $('#delete-reindex-status');
        
        if (!confirm(__('WARNING: Are you sure you want to DELETE ALL documents from your LupaSearch index and then reindex all products? This action cannot be undone and may take some time.', 'lupasearch-woo'))) {
            return;
        }
        
        button.prop('disabled', true);
        statusSpan.html(__('Processing... Step 1: Deleting all documents from LupaSearch...', 'lupasearch-woo')).removeClass('error success');
        
        $.ajax({
            url: lupaSearchAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'lupasearch_delete_all_and_reindex',
                nonce: lupaSearchAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    statusSpan.html('<span style="color: green;">' + (response.data.message || __('Successfully deleted all documents and reindexed products.', 'lupasearch-woo')) + '</span><br><small>Delete details: ' + (response.data.delete_details || '') + '<br>Reindex details: ' + (response.data.reindex_details || '') + '</small>');
                    // Optionally, trigger connection test to update stats
                    $('#test-connection').click();
                } else {
                    statusSpan.html('<span style="color: red;">' + __('Operation failed:', 'lupasearch-woo') + ' ' + (response.data.message || __('Unknown error', 'lupasearch-woo')) + '</span><br><small>Details: ' + (response.data.details || (response.data.delete_details || '') + ' ' + (response.data.reindex_details || '')) + '</small>');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                statusSpan.html('<span style="color: red;">' + __('Operation failed: AJAX error.', 'lupasearch-woo') + ' ' + textStatus + ': ' + errorThrown + '</span>');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });
});
