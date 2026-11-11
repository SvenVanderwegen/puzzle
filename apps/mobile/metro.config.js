// Lets the app import the website's rules engine
// (resources/js/lib/burnfront-engine.js) without copying it.
const path = require('path');
const { getDefaultConfig } = require('expo/metro-config');

const config = getDefaultConfig(__dirname);
config.watchFolders = [...(config.watchFolders ?? []), path.resolve(__dirname, '../../resources/js/lib')];

module.exports = config;
