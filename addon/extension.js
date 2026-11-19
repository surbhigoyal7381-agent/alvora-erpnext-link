import { ExtensionComponent, MenuItem } from '@fleetbase/ember-core/contracts';

const ENGINE = '@alvora/erpnext-link-engine';

export default {
    setupExtension(app, universe) {
        // The tab on Admin > Organizations > {organization}. Visibility is decided by the console's own admin route guard
        // and, for the data, by the server (AdminGuard on every call).
        universe.getService('menu').registerMenuItem(
            'console:admin:organization:tabs',
            new MenuItem({
                title: 'ERPNext',
                label: 'ERPNext',
                slug: 'erpnext',
                icon: 'plug',
                priority: 60,
                component: { engine: ENGINE, path: 'components/erpnext-tab' },
            })
        );

        // The status panel on the Overview tab of the same page.
        universe.getService('registry').registerRenderableComponent('console:admin:organization:overview-panels', new ExtensionComponent(ENGINE, 'components/erpnext-overview-panel'));
    },
};
