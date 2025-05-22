jQuery(document).ready(function($) {
    $('#test-connection').on('click', function() {
        const button = $(this);
        const statusEl = $('#connection-status');
        const detailsEl = $('#connection-details');
        
        button.prop('disabled', true);
        statusEl.html('Testing connection...');
        
        $.ajax({
            url: lupaSearchAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'test_lupa_connection',
                nonce: lupaSearchAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    statusEl.html('<span style="color: green;">✓ Connected successfully</span>');
                    detailsEl.show();
                    
                    // Update stats
                    $('#indexed-products').text(response.indexed_products || 0);
                    $('#active-indices').text(response.indices.length);
                    
                    // Render indices list
                    const indicesList = response.indices.map(index => `
                        <div class="index-item">
                            <span>${index.name} (${index.id})</span>
                            <span class="index-status ${index.isEnabled ? 'active' : 'inactive'}">
                                ${index.isEnabled ? 'Active' : 'Inactive'}
                            </span>
                        </div>
                    `).join('');
                    
                    $('#available-indices').html(indicesList);
                } else {
                    statusEl.html('<span style="color: red;">✕ ' + (response.message || 'Connection failed') + '</span>');
                    detailsEl.hide();
                }
            },
            error: function() {
                statusEl.html('<span style="color: red;">✕ Connection failed</span>');
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
        statusSpan.html('Generating documents...');
        
        $.ajax({
            url: lupaSearchAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'generate_lupasearch_documents',
                nonce: lupaSearchAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    statusSpan.html('<span style="color: green;">Documents generated successfully!</span>');
                    downloadJSON(response.data, response.filename);
                } else {
                    statusSpan.html('<span style="color: red;">Generation failed: ' + response.message + '</span>');
                }
            },
            error: function() {
                statusSpan.html('<span style="color: red;">Generation failed: Network error</span>');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });

    $('#import-documents').on('click', function() {
        const button = $(this);
        const statusSpan = $('#generation-status');
        
        if (!confirm('Are you sure you want to import documents to LupaSearch?')) {
            return;
        }
        
        button.prop('disabled', true);
        statusSpan.html('Importing documents to LupaSearch...');
        
        $.ajax({
            url: lupaSearchAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'import_lupasearch_documents',
                nonce: lupaSearchAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    statusSpan.html('<span style="color: green;">' + response.message + '</span>');
                } else {
                    statusSpan.html('<span style="color: red;">Import failed: ' + response.message + '</span>');
                }
            },
            error: function() {
                statusSpan.html('<span style="color: red;">Import failed: Network error</span>');
            },
            complete: function() {
                button.prop('disabled', false);
            }
        });
    });

    $('#reindex-all').on('click', function() {
        const button = $(this);
        const statusSpan = $('#reindex-status');
        
        if (!confirm('Are you sure you want to reindex all products? This may take a while.')) {
            return;
        }
        
        button.prop('disabled', true);
        statusSpan.html('Reindexing all products...');
        
        $.ajax({
            url: lupaSearchAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'reindex_lupasearch_products',
                nonce: lupaSearchAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    statusSpan.html('<span style="color: green;">' + response.message + '</span>');
                    setTimeout(() => location.reload(), 2000);
                } else {
                    statusSpan.html('<span style="color: red;">Reindex failed: ' + response.message + '</span>');
                }
            },
            error: function() {
                statusSpan.html('<span style="color: red;">Reindex failed: Network error</span>');
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
});
