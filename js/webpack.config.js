const path = require('path');

// flarum-webpack-config (this version) resolves entrypoints at the js/ root.
// The TypeScript source lives under src/, so point the entry there explicitly.
// Admin only for now: the member-facing half arrives with checkout.
const config = require('flarum-webpack-config')();

config.entry = {
    admin: path.resolve(__dirname, 'src/admin.ts'),
};

module.exports = config;
