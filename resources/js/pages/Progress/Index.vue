<script setup>
import { Head, Link, router } from '@statamic/cms/inertia';
import { dateFormatter } from '@statamic/cms/api';
import {
    Badge,
    Button,
    CommandPaletteItem,
    DocsCallout,
    EmptyStateItem,
    EmptyStateMenu,
    Header,
    Icon,
    Listing,
} from '@statamic/cms/ui';

const props = defineProps({
    locale: { type: String, default: null },
    rows: { type: Array, required: true },
    initialColumns: { type: Array, required: true },
    hasLearners: { type: Boolean, default: false },
    hasCollection: { type: Boolean, default: false },
    collectionUrl: { type: String, default: null },
    stuckHelp: { type: String, default: '' },
});

const docsUrl = 'https://github.com/goldnead/statamic-courses#readme';

function reload() {
    router.reload();
}

// Core's formatter with its `datetime` preset. The locale is the CP's (the
// user's language, from the server): core's own default falls back to the
// browser's language, which printed US dates into a German CP.
function formatDate(value) {
    if (!value) return null;

    return props.locale
        ? dateFormatter.withLocale(props.locale, () => dateFormatter.format(value, 'datetime'))
        : dateFormatter.format(value, 'datetime');
}
</script>

<template>
    <Head :title="__('courses::cp.title')" />

    <div class="max-w-page mx-auto">
        <template v-if="!hasCollection">
            <!-- Core's empty state is a centred h1 rather than <Header>; see pages/forms/Index.vue. -->
            <header class="py-8 pt-16 text-center">
                <h1 class="text-[25px] font-medium antialiased flex justify-center items-center gap-2 sm:gap-3">
                    <Icon name="content-book-open" class="size-5 text-gray-500" />{{ __('courses::cp.title') }}
                </h1>
            </header>

            <EmptyStateMenu :heading="__('courses::cp.empty_heading')">
                <EmptyStateItem
                    icon="content-book-open"
                    :heading="__('courses::cp.empty_install_heading')"
                    :description="__('courses::cp.empty_install_description')"
                    :href="docsUrl"
                    target="_blank"
                />
            </EmptyStateMenu>
        </template>

        <template v-else>
            <Header :title="__('courses::cp.title')" icon="content-book-open">
                <CommandPaletteItem
                    v-if="collectionUrl"
                    category="Actions"
                    :text="__('courses::cp.open_courses')"
                    icon="content-book-open"
                    :url="collectionUrl"
                    v-slot="{ text, url }"
                >
                    <Button :href="url" :text="text" variant="primary" />
                </CommandPaletteItem>
            </Header>

            <!--
                Client mode: a site has a handful of courses, so the rows come
                with the page. Read-only, hence no action-url and no checkboxes.
            -->
            <Listing
                :items="rows"
                :columns="initialColumns"
                preferences-prefix="courses.progress"
                sort-column="title"
                sort-direction="asc"
                @refreshing="reload"
            >
                <template #cell-title="{ row }">
                    <Link v-if="row.edit_url" :href="row.edit_url" class="title-index-field">{{ row.title }}</Link>
                    <span v-else>{{ row.title }}</span>
                </template>

                <template #cell-completion_rate="{ row }">{{ row.completion_rate }}&thinsp;%</template>

                <template #cell-stuck="{ row }">
                    <Badge v-if="row.stuck > 0" pill color="amber" :text="String(row.stuck)" v-tooltip="stuckHelp" />
                    <span v-else class="text-gray-500 dark:text-gray-400">0</span>
                </template>

                <template #cell-last_activity_at="{ row }">
                    <span v-if="row.last_activity_at" class="whitespace-nowrap">{{ formatDate(row.last_activity_at) }}</span>
                    <span v-else class="text-gray-500 dark:text-gray-400">&mdash;</span>
                </template>
            </Listing>
        </template>

        <DocsCallout :topic="__('courses::cp.title')" :url="docsUrl" />
    </div>
</template>
