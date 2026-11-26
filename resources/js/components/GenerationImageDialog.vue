<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import generationRoutes from '@/routes/generations';
import { DownloadIcon, EyeIcon, Loader2Icon } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    id: string;
    imageUrl: string | null;
    size: string;
    prompt: string;
}>();

const open = ref(false);
const previewFailed = ref(false);
const thumbFailed = ref(false);
const downloading = ref(false);
const downloadError = ref<string | null>(null);

const expired = computed(() => props.imageUrl === null);
const triggerDisabled = computed(() => expired.value && !open.value);

watch(
    () => props.imageUrl,
    () => {
        previewFailed.value = false;
        thumbFailed.value = false;
    },
);

watch(open, (isOpen) => {
    if (isOpen) {
        previewFailed.value = false;
        downloadError.value = null;
    }
});

async function download(): Promise<void> {
    if (downloading.value || expired.value) {
        return;
    }

    downloading.value = true;
    downloadError.value = null;

    try {
        const response = await fetch(generationRoutes.download(props.id).url, {
            credentials: 'same-origin',
        });
        const contentType = (
            response.headers.get('content-type') ?? ''
        ).toLowerCase();

        if (!response.ok || !contentType.startsWith('image/png')) {
            throw new Error('download unavailable');
        }

        const blob = await response.blob();
        const href = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = href;
        anchor.download = `pixelforge-${props.id}.png`;
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
        window.setTimeout(() => URL.revokeObjectURL(href), 1000);
    } catch {
        downloadError.value =
            'The download is unavailable right now. Try again in a moment.';
    } finally {
        downloading.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <button
                type="button"
                :disabled="triggerDisabled"
                class="group relative flex h-full w-full cursor-pointer items-center justify-center focus-visible:ring-2 focus-visible:ring-pf-cobalt focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-default"
            >
                <img
                    v-if="imageUrl && !thumbFailed"
                    :src="imageUrl"
                    alt=""
                    loading="lazy"
                    referrerpolicy="no-referrer"
                    class="h-full w-full object-cover"
                    @error="thumbFailed = true"
                />
                <span v-else-if="imageUrl" class="text-sm text-slate-400"
                    >Preview unavailable</span
                >
                <span v-else class="text-sm text-slate-400">Expired</span>
                <span
                    v-if="imageUrl"
                    aria-hidden="true"
                    class="pointer-events-none absolute inset-0 flex items-center justify-center bg-black/0 opacity-0 transition duration-200 group-hover:bg-black/45 group-hover:opacity-100 group-focus-visible:bg-black/45 group-focus-visible:opacity-100"
                    ><EyeIcon class="size-7 text-white" /></span
                >
                <span class="sr-only">{{
                    imageUrl
                        ? `Preview image — ${prompt}`
                        : 'Image link expired'
                }}</span>
            </button>
        </DialogTrigger>
        <DialogContent class="sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>Generated image</DialogTitle>
                <DialogDescription
                    >{{ size }} PNG, served by PixelForge.</DialogDescription
                >
            </DialogHeader>
            <div
                class="flex max-h-[70vh] items-center justify-center overflow-auto bg-[#141414]"
            >
                <p
                    v-if="expired"
                    class="px-6 py-10 text-center text-sm text-slate-300"
                >
                    The signed link for this image has expired. It can no longer
                    be previewed or downloaded.
                </p>
                <p
                    v-else-if="previewFailed"
                    class="px-6 py-10 text-center text-sm text-slate-300"
                >
                    The preview could not be loaded. You can still download the
                    PNG.
                </p>
                <img
                    v-else
                    :src="imageUrl ?? undefined"
                    alt="Generated image"
                    referrerpolicy="no-referrer"
                    class="max-h-[70vh] max-w-full object-contain"
                    @error="previewFailed = true"
                />
            </div>
            <DialogFooter v-if="!expired" class="sm:justify-start">
                <button
                    type="button"
                    :disabled="downloading"
                    class="inline-flex items-center gap-1.5 bg-pf-cobalt px-3 py-1.5 text-xs font-semibold text-white hover:brightness-110 focus-visible:ring-2 focus-visible:ring-pf-cobalt focus-visible:ring-offset-2 focus-visible:outline-none disabled:opacity-60"
                    @click="download"
                >
                    <Loader2Icon
                        v-if="downloading"
                        class="size-3.5 motion-safe:animate-spin"
                        aria-hidden="true"
                    />
                    <DownloadIcon v-else class="size-3.5" aria-hidden="true" />
                    {{ downloading ? 'Preparing download…' : 'Download PNG' }}
                </button>
                <p
                    v-if="downloadError"
                    role="alert"
                    class="text-xs text-red-600 dark:text-red-400"
                >
                    {{ downloadError }}
                </p>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
