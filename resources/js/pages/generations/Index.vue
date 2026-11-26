<script setup lang="ts">
import GenerationImageDialog from '@/components/GenerationImageDialog.vue';
import { Button } from '@/components/ui/button';
import generationRoutes from '@/routes/generations';
import type {
    GenerationHistoryPage,
    GenerationHistoryRow,
} from '@/types/generations';
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { Clock3, Loader2Icon, RefreshCw } from '@lucide/vue';
import { useDocumentVisibility } from '@vueuse/core';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watchEffect,
} from 'vue';

type GenerationRow = GenerationHistoryRow;

const PROMPT_PREVIEW_LENGTH = 120;

const props = defineProps<{
    generations: GenerationHistoryPage;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'History',
                href: generationRoutes.index().url,
            },
        ],
    },
});

const isActive = (row: GenerationRow): boolean =>
    !row.needs_attention &&
    (row.status === 'queued' || row.status === 'processing');

const isParked = (row: GenerationRow): boolean => row.needs_attention;

const hasActive = computed(() =>
    props.generations.data.some((row) => isActive(row)),
);

const { start: startPolling, stop: stopPolling } = usePoll(
    5000,
    { only: ['generations'] },
    { autoStart: false },
);

const visibility = useDocumentVisibility();

watchEffect(() => {
    if (hasActive.value && visibility.value === 'visible') {
        startPolling();
    } else {
        stopPolling();
    }
});

const mounted = ref(false);
const now = ref<Date | null>(null);
let expiryTimer: number | undefined;

onMounted(() => {
    mounted.value = true;
    now.value = new Date();
    expiryTimer = window.setInterval(() => {
        now.value = new Date();
    }, 15_000);
});

onBeforeUnmount(() => {
    if (expiryTimer !== undefined) {
        window.clearInterval(expiryTimer);
    }
});

const imageLive = (row: GenerationRow): boolean =>
    row.image_url !== null &&
    row.image_expires_at !== null &&
    (now.value === null ||
        new Date(row.image_expires_at).getTime() > now.value.getTime());

const expanded = reactive(new Set<string>());

const isLongPrompt = (prompt: string): boolean =>
    [...prompt].length > PROMPT_PREVIEW_LENGTH;

const promptText = (prompt: string): string =>
    isLongPrompt(prompt)
        ? `${[...prompt].slice(0, PROMPT_PREVIEW_LENGTH).join('')}…`
        : prompt;

const togglePrompt = (id: string): void => {
    if (expanded.has(id)) {
        expanded.delete(id);
    } else {
        expanded.add(id);
    }
};

const statusLabel = (row: GenerationRow): string => {
    if (row.needs_attention) {
        return 'Needs attention';
    }

    switch (row.status) {
        case 'queued':
            return 'Queued';
        case 'processing':
            return 'Generating';
        case 'succeeded':
            return 'Completed';
        case 'failed':
            return 'Failed';
        default:
            return row.status;
    }
};

const statusNote = (row: GenerationRow): string | null => {
    if (row.needs_attention) {
        return 'Confirmation unavailable. No charge has been recorded yet.';
    }

    switch (row.status) {
        case 'queued':
            return row.is_delayed
                ? 'Still waiting to start.'
                : 'Waiting for a start confirmation.';
        case 'processing':
            return row.is_delayed
                ? 'Taking longer than usual.'
                : 'PixelForge is creating your image.';
        default:
            return null;
    }
};

const thumbState = (
    row: GenerationRow,
): 'queued' | 'processing' | 'failed' | 'expired' | 'empty' => {
    if (row.status === 'queued') {
        return 'queued';
    }

    if (row.status === 'processing') {
        return 'processing';
    }

    if (row.status === 'failed') {
        return 'failed';
    }

    if (row.image_expired) {
        return 'expired';
    }

    return 'empty';
};

const metaLine = (row: GenerationRow): string => {
    const quality = `${row.quality.charAt(0).toUpperCase()}${row.quality.slice(1)}`;
    const references =
        row.reference_count > 0
            ? ` · ${row.reference_count} reference${row.reference_count === 1 ? '' : 's'}`
            : '';
    const created = row.created_at ? ` · ${displayDate(row.created_at)}` : '';

    return `${row.size} · ${quality}${references}${created}`;
};

const displayDate = (iso: string): string =>
    mounted.value ? formatDate(iso) : iso;

const formatDate = (iso: string): string =>
    new Date(iso).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    });

const refresh = (): void => {
    router.reload({ only: ['generations'] });
};
</script>

<template>
    <Head title="History" />

    <div class="px-4 py-6 sm:px-6 lg:px-8">
        <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-3xl font-semibold tracking-tight">History</h1>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    Review your images and their final charges.
                </p>
                <p
                    v-if="hasActive"
                    class="mt-1 text-xs text-slate-500 dark:text-slate-400"
                >
                    Checking for updates automatically.
                </p>
            </div>
            <div class="flex items-center gap-2">
                <Button variant="outline" size="sm" @click="refresh">
                    <RefreshCw class="size-4" aria-hidden="true" />
                    Refresh
                </Button>
                <Button size="sm" as-child>
                    <Link :href="generationRoutes.create().url"
                        >Create an image</Link
                    >
                </Button>
            </div>
        </div>

        <ul
            v-if="generations.data.length > 0"
            class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3"
        >
            <li v-for="row in generations.data" :key="row.id">
                <figure>
                    <div
                        class="relative aspect-[4/3] overflow-hidden bg-[#141414]"
                    >
                        <span
                            role="status"
                            class="absolute top-2 left-2 z-10 rounded bg-black/55 px-2 py-0.5 text-xs font-medium text-white"
                            >{{ statusLabel(row)
                            }}<span v-if="statusNote(row)" class="sr-only">
                                — {{ statusNote(row) }}</span
                            ></span
                        >

                        <GenerationImageDialog
                            v-if="row.status === 'succeeded'"
                            :id="row.id"
                            :image-url="imageLive(row) ? row.image_url : null"
                            :size="row.size"
                            :prompt="row.prompt"
                        />
                        <div
                            v-else-if="isActive(row)"
                            class="flex h-full w-full items-center justify-center bg-gradient-to-br from-[#181818] via-[#141414] to-[#0f0f0f]"
                        >
                            <Clock3
                                v-if="row.status === 'queued'"
                                class="size-6 text-pf-cobalt motion-safe:animate-pulse"
                                aria-hidden="true"
                            />
                            <Loader2Icon
                                v-else
                                class="size-6 text-pf-cobalt motion-safe:animate-spin"
                                aria-hidden="true"
                            />
                        </div>
                        <div
                            v-else
                            class="flex h-full w-full items-center justify-center bg-gradient-to-br from-[#181818] via-[#141414] to-[#0f0f0f] px-4 text-center text-sm"
                            :class="
                                thumbState(row) === 'failed'
                                    ? 'text-red-300'
                                    : 'text-slate-400'
                            "
                        >
                            <span v-if="thumbState(row) === 'failed'"
                                >Failed</span
                            >
                            <span v-else-if="thumbState(row) === 'expired'"
                                >Expired</span
                            >
                            <span v-else>No image</span>
                        </div>
                        <span
                            v-if="isActive(row)"
                            aria-hidden="true"
                            class="absolute inset-x-0 bottom-0 block h-0.5 bg-pf-cobalt/20"
                            ><span
                                class="pf-thumb-sweep absolute inset-y-0 left-0 block w-1/2 bg-pf-cobalt"
                        /></span>
                    </div>

                    <figcaption class="mt-3">
                        <div
                            v-if="statusNote(row) || isParked(row)"
                            class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400"
                        >
                            <p aria-hidden="true">{{ statusNote(row) }}</p>
                            <button
                                v-if="isParked(row)"
                                type="button"
                                class="inline-flex shrink-0 cursor-pointer items-center gap-1 font-medium text-pf-cobalt underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-pf-cobalt focus-visible:outline-none"
                                @click="refresh"
                            >
                                <RefreshCw
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                Refresh
                            </button>
                        </div>
                        <p
                            :id="`prompt-${row.id}`"
                            class="pf-prompt-scroll mt-2 text-sm leading-6 break-words"
                            :class="{
                                'max-h-32 overflow-y-auto whitespace-pre-line':
                                    expanded.has(row.id),
                            }"
                        >
                            {{
                                expanded.has(row.id)
                                    ? row.prompt
                                    : promptText(row.prompt)
                            }}
                        </p>
                        <button
                            v-if="isLongPrompt(row.prompt)"
                            type="button"
                            class="mt-1 cursor-pointer text-xs font-medium text-pf-cobalt underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-pf-cobalt focus-visible:outline-none"
                            :aria-expanded="expanded.has(row.id)"
                            :aria-controls="`prompt-${row.id}`"
                            @click="togglePrompt(row.id)"
                        >
                            {{
                                expanded.has(row.id) ? 'Show less' : 'Read more'
                            }}
                        </button>
                        <p
                            class="mt-2 text-xs text-slate-500 dark:text-slate-400"
                        >
                            {{ metaLine(row) }}
                        </p>
                        <p
                            v-if="row.failure_message"
                            class="mt-1 text-xs leading-5 text-red-600 dark:text-red-400"
                        >
                            {{ row.failure_message }}
                        </p>
                        <p
                            class="mt-1 text-xs text-slate-500 dark:text-slate-400"
                        >
                            Estimated ${{ row.estimated_min_usd }}–${{
                                row.estimated_max_usd
                            }}
                        </p>
                        <p
                            class="mt-1 text-xs text-slate-500 dark:text-slate-400"
                        >
                            Final charge
                            <template v-if="row.charge_usd !== null"
                                >${{ row.charge_usd }}</template
                            >
                            <template v-else>pending</template>
                        </p>
                    </figcaption>
                </figure>
            </li>
        </ul>

        <div
            v-else
            class="border border-slate-200 bg-white p-10 text-center dark:border-slate-800 dark:bg-slate-950"
        >
            <p class="text-sm font-medium">No generations yet</p>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Submitted prompts show up here with their status, estimate, and
                final charge.
            </p>
            <Button class="mt-4" size="sm" as-child>
                <Link :href="generationRoutes.create().url"
                    >Create an image</Link
                >
            </Button>
        </div>

        <nav
            v-if="
                generations.prev_page_url !== null ||
                generations.next_page_url !== null
            "
            aria-label="Pagination"
            class="mt-6 flex items-center gap-2"
        >
            <Link
                v-if="generations.prev_page_url"
                :href="generations.prev_page_url"
                class="px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800"
                >Newer</Link
            >
            <Link
                v-if="generations.next_page_url"
                :href="generations.next_page_url"
                class="px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-100 dark:text-slate-200 dark:hover:bg-slate-800"
                >Older</Link
            >
        </nav>
    </div>
</template>

<style scoped>
.pf-prompt-scroll {
    scrollbar-width: none;
    -ms-overflow-style: none;
}

.pf-prompt-scroll::-webkit-scrollbar {
    display: none;
}

.pf-thumb-sweep {
    animation: pf-thumb-sweep 1.4s ease-in-out infinite;
}

@keyframes pf-thumb-sweep {
    0% {
        transform: translateX(-100%);
    }
    55% {
        transform: translateX(200%);
    }
    100% {
        transform: translateX(200%);
    }
}

@media (prefers-reduced-motion: reduce) {
    .pf-thumb-sweep {
        animation: none;
        transform: translateX(25%);
    }
}
</style>
