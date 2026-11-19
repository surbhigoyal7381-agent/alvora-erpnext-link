'use strict';
const { buildEngine } = require('ember-engines/lib/engine-addon');
const { name } = require('./package');

module.exports = buildEngine({
    name,

    lazyLoading: {
        // Lazy in the console, as every Fleetbase engine. Not lazy under `pnpm test:ember`, where the dummy app imports the
        // engine's own modules directly and there is no console to load a separate bundle.
        enabled: process.env.EMBER_ENGINE_EAGER !== '1',
    },

    isDevelopingAddon() {
        return true;
    },
});
