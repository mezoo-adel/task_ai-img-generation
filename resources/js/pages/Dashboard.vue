<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import ApertureStudy from '@/components/landing/ApertureStudy.vue';
import { dashboard } from '@/routes';
import generations from '@/routes/generations';
import type { GenerationStatus } from '@/types/generations';

type RecentGeneration = {
    id: string;
    prompt: string;
    size: string;
    quality: string;
    status: GenerationStatus;
    needs_attention: boolean;
};

defineProps<{
    recentGenerations: RecentGeneration[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const statusLabel = (row: RecentGeneration): string => {
    if (row.needs_attention) {
        return 'Needs attention';
    }
    if (row.status === 'succeeded') {
        return 'Completed';
    }
    if (row.status === 'failed') {
        return 'Failed';
    }
    return row.status === 'queued' ? 'Queued' : 'Generating';
};
</script>

<template>
    <Head title="Dashboard" />

    <div
        class="flex h-full flex-1 flex-col gap-8 overflow-x-auto rounded-xl p-4 md:p-6"
    >
        <section
            class="dash-hero overflow-hidden rounded-xl border border-[var(--dash-rule)]"
            aria-labelledby="dashboard-hero-heading"
        >
            <div
                class="grid gap-8 p-6 sm:p-8 lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)] lg:items-center"
            >
                <div class="max-w-md">
                    <h1
                        id="dashboard-hero-heading"
                        class="text-3xl font-semibold tracking-tight text-[var(--dash-paper)] sm:text-4xl"
                    >
                        Image studio
                    </h1>
                    <p
                        class="mt-3 text-sm leading-relaxed text-[var(--dash-muted)] sm:text-base"
                    >
                        Start with a prompt. Check the estimate before you
                        generate.
                    </p>
                    <div class="mt-6">
                        <Link
                            :href="generations.create()"
                            class="inline-flex items-center rounded-sm bg-[var(--dash-paper)] px-5 py-2.5 text-sm font-medium text-[var(--dash-ink)] transition-colors hover:bg-white"
                        >
                            Create an image
                        </Link>
                    </div>
                </div>

                <div
                    class="aspect-[4/3] overflow-hidden rounded-md border border-[var(--dash-rule)] sm:aspect-[2/1]"
                    aria-hidden="true"
                >
                    <ApertureStudy
                        preserveAspectRatio="xMidYMid slice"
                        class="!h-full w-full"
                    />
                </div>
            </div>
        </section>

        <section aria-labelledby="recent-heading">
            <div class="flex items-baseline justify-between gap-4">
                <h2 id="recent-heading" class="text-base font-semibold">
                    Recent generations
                </h2>
                <Link
                    :href="generations.index()"
                    class="text-sm text-muted-foreground transition-colors hover:text-foreground"
                >
                    View history
                </Link>
            </div>

            <ul v-if="recentGenerations.length" class="mt-4">
                <li
                    v-for="row in recentGenerations"
                    :key="row.id"
                    class="grid grid-cols-[minmax(0,1fr)_auto] items-baseline gap-x-4 border-t border-border py-4 first:border-t-0"
                >
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">
                            {{ row.prompt }}
                        </p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{ row.size }} &middot; {{ row.quality }}
                        </p>
                    </div>
                    <span
                        class="text-right text-xs font-medium"
                        :class="
                            row.needs_attention || row.status === 'failed'
                                ? 'text-muted-foreground'
                                : 'text-foreground'
                        "
                    >
                        {{ statusLabel(row) }}
                    </span>
                </li>
            </ul>

            <div v-else class="mt-4 border-t border-border py-8 text-center">
                <p class="text-sm font-medium">No generations yet</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    Your three most recent generations will appear here once you
                    create your first image.
                </p>
            </div>
        </section>
    </div>
</template>

<style scoped>
.dash-hero {
    --dash-ink: #080808;
    --dash-rule: #2b2b2b;
    --dash-muted: #a6a6a6;
    --dash-paper: #f4f4f4;

    background-color: var(--dash-ink);
}
</style>
