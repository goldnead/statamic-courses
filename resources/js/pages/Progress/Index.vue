<script setup>
import { Head, router } from '@statamic/cms/inertia';
import { Badge, DocsCallout, EmptyStateItem, EmptyStateMenu, Header, Icon, Listing } from '@statamic/cms/ui';

defineProps({
    rows: { type: Array, required: true },
    initialColumns: { type: Array, required: true },
    hasLearners: { type: Boolean, default: false },
    stuckHelp: { type: String, default: '' },
});

const docsUrl = 'https://github.com/goldnead/statamic-courses#readme';

function reload() {
    router.reload();
}

function formatDate(value) {
    return value ? new Date(value).toLocaleString() : null;
}
</script>

<template>
    <Head :title="__('courses::cp.title')" />

    <div class="max-w-page mx-auto">
        <template v-if="!hasLearners">
            <!-- Core's empty state is a centred h1 rather than <Header>; see pages/forms/Index.vue. -->
            <header class="py-8 pt-16 text-center">
                <h1 class="text-[25px] font-medium antialiased flex justify-center items-center gap-2 sm:gap-3">
                    <Icon name="chart-monitoring-indicator" class="size-5 text-gray-500" />{{ __('courses::cp.title') }}
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
                <EmptyStateItem
                    icon="bookmark"
                    :heading="__('courses::cp.empty_docs_heading')"
                    :description="__('courses::cp.empty_docs_description')"
                    :href="docsUrl"
                    target="_blank"
                />
            </EmptyStateMenu>
        </template>

        <template v-else>
            <Header :title="__('courses::cp.title')" icon="chart-monitoring-indicator" />

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
