import { inertia } from '@statamic/cms/api';

import ProgressIndex from './pages/Progress/Index.vue';

/*
 * The `courses::` prefix keeps this page out of core's names and every other
 * addon's. Registered inside Statamic.booting, because `inertia` belongs to the
 * CP runtime this bundle is externalised against.
 */
Statamic.booting(() => {
    inertia.register('courses::Progress/Index', ProgressIndex);
});
