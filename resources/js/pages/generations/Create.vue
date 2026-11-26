<script setup lang="ts">
import { Head, Link, useForm, useHttp } from '@inertiajs/vue3';
import { Loader2Icon, Plus, X } from '@lucide/vue';
import { computed, onUnmounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import generations from '@/routes/generations';
import type {
    EstimateBounds,
    GenerationInput,
    GenerationQuality,
    GenerationSize,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Create an image',
                href: generations.create(),
            },
        ],
    },
});

const PROMPT_LIMIT = 4000;
const MAX_REFERENCES = 4;

const SIZES: {
    value: GenerationSize;
    label: string;
    note: string;
    aspect: string;
}[] = [
    {
        value: '1024x1024',
        label: 'Square',
        note: '1024 × 1024',
        aspect: '1 / 1',
    },
    {
        value: '1536x1024',
        label: 'Landscape',
        note: '1536 × 1024',
        aspect: '3 / 2',
    },
    {
        value: '1024x1536',
        label: 'Portrait',
        note: '1024 × 1536',
        aspect: '2 / 3',
    },
];

const QUALITIES: { value: GenerationQuality; label: string }[] = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
];

const generationForm = useForm<GenerationInput>({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [],
});

// Both endpoints receive the exact same normalized body.
generationForm.transform(() => inputSnapshot.value);

const promptCount = computed(() => Array.from(generationForm.prompt).length);

const isHttpUrl = (value: string): boolean => {
    try {
        const url = new URL(value);
        return url.protocol === 'http:' || url.protocol === 'https:';
    } catch {
        return false;
    }
};

const invalidReferences = computed<boolean[]>(() =>
    generationForm.reference_images.map(
        (reference) => reference.trim() !== '' && !isHttpUrl(reference.trim()),
    ),
);

const inputSnapshot = computed<GenerationInput>(() => ({
    prompt: generationForm.prompt,
    size: generationForm.size,
    quality: generationForm.quality,
    reference_images: generationForm.reference_images
        .map((reference) => reference.trim())
        .filter((reference) => reference !== ''),
}));

const inputValid = computed(
    () =>
        generationForm.prompt.trim() !== '' &&
        promptCount.value <= PROMPT_LIMIT &&
        invalidReferences.value.every((invalid) => !invalid),
);

const addReference = () => {
    if (generationForm.reference_images.length < MAX_REFERENCES) {
        generationForm.reference_images.push('');
    }
};

const removeReference = (index: number) => {
    generationForm.reference_images.splice(index, 1);
};

const updateReference = (index: number, event: Event) => {
    generationForm.reference_images[index] = (
        event.target as HTMLInputElement
    ).value;
};

// Live estimate: debounced against the valid input snapshot, invalidated
// synchronously whenever the input moves so a stale price is never shown.
const estimateForm = useHttp<GenerationInput, EstimateBounds>({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [],
});

const estimateBounds = ref<EstimateBounds | null>(null);
const estimateFailed = ref(false);
const estimating = ref(false);
const estimateErrors = ref<string[]>([]);
let estimateKey: string | null = null;
let revision = 0;
const ESTIMATE_DEBOUNCE_MS = 2000;
let debounceId: ReturnType<typeof setTimeout> | undefined;

const inputKey = computed(() => JSON.stringify(inputSnapshot.value));
const estimateCurrent = computed(
    () => estimateBounds.value !== null && estimateKey === inputKey.value,
);
const pricingError = computed(
    () => (generationForm.errors as Record<string, string>).pricing,
);

const fieldErrorLabels = (errors: Record<string, string>): string[] =>
    Object.entries(errors).map(([field, message]) => {
        const referenceMatch = /^reference_images\.(\d+)$/.exec(field);
        if (referenceMatch) {
            return `Reference link ${Number(referenceMatch[1]) + 1}: ${message}`;
        }
        return message;
    });

const requestEstimate = async (snapshot: GenerationInput, rev: number) => {
    estimating.value = true;
    try {
        Object.assign(estimateForm, snapshot);
        const result = await estimateForm.post(generations.estimate.url());
        if (rev !== revision) {
            return;
        }
        if (result === undefined) {
            estimateBounds.value = null;
            estimateKey = null;
            estimateErrors.value = fieldErrorLabels(
                estimateForm.errors as Record<string, string>,
            );
            estimateFailed.value = true;
        } else {
            estimateBounds.value = result;
            estimateKey = JSON.stringify(snapshot);
        }
    } catch (error) {
        if (
            rev !== revision ||
            (error instanceof Error && error.name === 'HttpCancelledError')
        ) {
            return;
        }
        estimateBounds.value = null;
        estimateKey = null;
        estimateFailed.value = true;
    } finally {
        if (rev === revision) {
            estimating.value = false;
        }
    }
};

watch(
    inputSnapshot,
    (snapshot) => {
        revision += 1;
        estimateForm.cancel();
        estimateBounds.value = null;
        estimateKey = null;
        estimateFailed.value = false;
        estimateErrors.value = [];
        clearTimeout(debounceId);

        if (!inputValid.value) {
            estimating.value = false;
            return;
        }

        const rev = revision;
        debounceId = setTimeout(
            () => void requestEstimate(snapshot, rev),
            ESTIMATE_DEBOUNCE_MS,
        );
    },
    { immediate: true },
);

const retryEstimate = () => {
    if (!inputValid.value) {
        return;
    }
    clearTimeout(debounceId);
    estimateFailed.value = false;
    estimateErrors.value = [];
    revision += 1;
    estimateForm.cancel();
    void requestEstimate(inputSnapshot.value, revision);
};

onUnmounted(() => {
    revision += 1;
    clearTimeout(debounceId);
    estimateForm.cancel();
});

const submit = () => {
    // The button is locked until the estimate matches the active input,
    // and the server recomputes the bounds itself either way.
    if (generationForm.processing || !estimateCurrent.value) {
        return;
    }

    generationForm.post(generations.store.url(), {
        preserveScroll: true,
        onError: (errors) => {
            if (errors.pricing) {
                estimateBounds.value = null;
                estimateKey = null;
                estimateFailed.value = true;
            }
        },
    });
};

const currentSize = computed(
    () => SIZES.find((size) => size.value === generationForm.size) ?? SIZES[0],
);

const proofStyle = computed(() => {
    if (generationForm.size === '1024x1536') {
        return { aspectRatio: '2 / 3', height: '78%' };
    }
    if (generationForm.size === '1536x1024') {
        return { aspectRatio: '3 / 2', width: '86%' };
    }
    return { aspectRatio: '1 / 1', width: '68%' };
});
</script>

<template>
    <Head title="Create an image" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-baseline justify-between gap-2">
            <div class="space-y-0.5">
                <h1 class="text-xl font-semibold tracking-tight">
                    Create an image
                </h1>
                <p class="text-sm text-muted-foreground">
                    Describe the image, pick a size and quality, then check the
                    estimate before generating.
                </p>
            </div>
            <Link
                :href="generations.index()"
                class="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                >History</Link
            >
        </header>

        <form
            class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_320px]"
            novalidate
            @submit.prevent="submit"
        >
            <div class="flex min-w-0 flex-col gap-6">
                <div class="grid gap-2">
                    <div class="flex items-baseline justify-between gap-2">
                        <Label for="prompt">Prompt</Label>
                        <span
                            class="text-xs text-muted-foreground tabular-nums"
                            aria-hidden="true"
                            >{{ promptCount }} / {{ PROMPT_LIMIT }}</span
                        >
                    </div>
                    <textarea
                        id="prompt"
                        v-model="generationForm.prompt"
                        rows="5"
                        placeholder="A quiet reading nook by a rainy window…"
                        class="flex min-h-32 w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30 dark:aria-invalid:ring-destructive/40"
                        :aria-invalid="
                            generationForm.errors.prompt ? true : undefined
                        "
                        aria-describedby="prompt-count"
                    />
                    <InputError :message="generationForm.errors.prompt" />
                </div>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Size</legend>
                    <div class="grid grid-cols-3 gap-2">
                        <label
                            v-for="size in SIZES"
                            :key="size.value"
                            class="flex cursor-pointer flex-col items-center gap-2 rounded-md border border-input p-3 text-center has-checked:border-pf-cobalt has-checked:ring-1 has-checked:ring-pf-cobalt has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50 motion-safe:transition-[border-color,box-shadow]"
                        >
                            <input
                                v-model="generationForm.size"
                                type="radio"
                                name="size"
                                :value="size.value"
                                class="sr-only"
                            />
                            <span class="flex h-10 items-center justify-center">
                                <span
                                    class="block rounded-[2px] border-2 border-current"
                                    :style="{
                                        aspectRatio: size.aspect,
                                        height:
                                            size.value === '1024x1536'
                                                ? '2.25rem'
                                                : '1.75rem',
                                    }"
                                    aria-hidden="true"
                                />
                            </span>
                            <span class="text-sm font-medium">{{
                                size.label
                            }}</span>
                            <span class="text-xs text-muted-foreground">{{
                                size.note
                            }}</span>
                        </label>
                    </div>
                    <InputError :message="generationForm.errors.size" />
                </fieldset>

                <fieldset class="grid gap-2">
                    <legend class="mb-2 text-sm font-medium">Quality</legend>
                    <div class="grid grid-cols-3 gap-2">
                        <label
                            v-for="quality in QUALITIES"
                            :key="quality.value"
                            class="flex cursor-pointer items-center justify-center rounded-md border border-input px-3 py-2 text-sm has-checked:border-pf-cobalt has-checked:ring-1 has-checked:ring-pf-cobalt has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50 motion-safe:transition-[border-color,box-shadow]"
                        >
                            <input
                                v-model="generationForm.quality"
                                type="radio"
                                name="quality"
                                :value="quality.value"
                                class="sr-only"
                            />
                            {{ quality.label }}
                        </label>
                    </div>
                    <InputError :message="generationForm.errors.quality" />
                </fieldset>

                <fieldset class="grid gap-2">
                    <legend class="mb-1 text-sm font-medium">
                        Reference links
                        <span class="font-normal text-muted-foreground"
                            >(optional, up to {{ MAX_REFERENCES }})</span
                        >
                    </legend>
                    <div
                        v-for="(
                            reference, index
                        ) in generationForm.reference_images"
                        :key="index"
                        class="grid gap-1"
                    >
                        <div class="flex items-center gap-2">
                            <input
                                :value="reference"
                                type="url"
                                placeholder="https://example.com/image.png"
                                class="h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none selection:bg-primary selection:text-primary-foreground placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30 dark:aria-invalid:ring-destructive/40"
                                v-bind="{
                                    'aria-label': `Reference link ${index + 1}`,
                                    'aria-invalid':
                                        invalidReferences[index] ||
                                        generationForm.errors[
                                            `reference_images.${index}`
                                        ]
                                            ? true
                                            : undefined,
                                }"
                                @input="updateReference(index, $event)"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                :aria-label="`Remove reference link ${index + 1}`"
                                @click="removeReference(index)"
                            >
                                <X class="size-4" />
                            </Button>
                        </div>
                        <p
                            v-if="invalidReferences[index]"
                            class="text-xs text-muted-foreground"
                        >
                            Enter a valid http or https link, or remove this
                            field.
                        </p>
                        <InputError
                            :message="
                                generationForm.errors[
                                    `reference_images.${index}`
                                ]
                            "
                        />
                    </div>
                    <div>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="
                                generationForm.reference_images.length >=
                                MAX_REFERENCES
                            "
                            @click="addReference"
                        >
                            <Plus class="size-4" />
                            Add a reference link
                        </Button>
                    </div>
                    <InputError
                        :message="generationForm.errors.reference_images"
                    />
                </fieldset>
            </div>

            <aside
                class="flex min-w-0 flex-col gap-4 rounded-xl border border-pf-frame bg-pf-proof p-4"
            >
                <div
                    class="mx-auto flex aspect-square w-full max-w-40 items-center justify-center lg:max-w-none"
                >
                    <div
                        class="rounded-[3px] border-2 border-dashed border-pf-cobalt/60"
                        :style="proofStyle"
                        aria-hidden="true"
                    />
                </div>
                <p
                    class="text-center text-xs text-muted-foreground tabular-nums"
                >
                    {{ currentSize.note }} px
                </p>

                <div class="space-y-1" aria-live="polite">
                    <p class="text-sm font-medium">Estimated cost</p>
                    <p
                        v-if="estimateCurrent && estimateBounds"
                        class="text-lg font-semibold tabular-nums"
                    >
                        ${{ estimateBounds.estimated_min_usd }} – ${{
                            estimateBounds.estimated_max_usd
                        }}
                    </p>
                    <p v-else class="text-sm text-muted-foreground">
                        {{
                            estimating || (inputValid && !estimateFailed)
                                ? 'Estimating…'
                                : estimateFailed
                                  ? 'Estimate unavailable.'
                                  : 'Enter a prompt to see the estimate.'
                        }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Includes the 25% service charge. The final charge
                        reflects actual usage.
                    </p>
                </div>

                <div v-if="estimateFailed" class="space-y-2">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-xs text-destructive">
                            Estimate unavailable.
                        </p>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="retryEstimate"
                        >
                            Retry
                        </Button>
                    </div>
                    <ul
                        v-if="estimateErrors.length"
                        class="space-y-0.5 text-xs text-destructive"
                    >
                        <li v-for="message in estimateErrors" :key="message">
                            {{ message }}
                        </li>
                    </ul>
                </div>

                <InputError :message="pricingError" />

                <Button
                    type="submit"
                    class="cursor-pointer bg-pf-cobalt text-pf-on-cobalt hover:bg-pf-cobalt/90"
                    :disabled="generationForm.processing || !estimateCurrent"
                    data-test="generate-button"
                >
                    <Loader2Icon
                        v-if="generationForm.processing"
                        class="size-4 motion-safe:animate-spin"
                        aria-hidden="true"
                    />
                    {{ generationForm.processing ? 'Queuing…' : 'Generate' }}
                </Button>
            </aside>
        </form>
    </div>
</template>
