const defaultConfig = require('@wordpress/scripts/config/webpack.config');

module.exports = {
    ...defaultConfig,
    entry: {
        'search-box': './blocks/src/search-box/index.js',
        'search-results': './blocks/src/search-results/index.js',
    }
};