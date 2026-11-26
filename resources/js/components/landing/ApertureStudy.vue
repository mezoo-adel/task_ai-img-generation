<script setup lang="ts">
import { useMediaQuery } from '@vueuse/core';
import { computed } from 'vue';

type Contour = {
    cx: number;
    cy: number;
    rx: number;
    ry: number;
    rot: number;
    tone: number;
    width: number;
    opacity: number;
};

// One luminous iris drawn as ~90 fine ellipses whose centers spiral inward
// while the twist accumulates — crisp, organic, not nested boxes.
const contours = computed<Contour[]>(() => {
    const count = 92;
    const list: Contour[] = [];

    for (let i = 0; i < count; i++) {
        const t = i / (count - 1); // 0 = outermost, 1 = innermost
        const rot = -t * 126; // angle decreases across radii
        const rad = (rot * Math.PI) / 180;
        const drift = 1 - t;

        const cx = 420 + 55 * drift * Math.cos(rad * 0.9);
        const cy = 310 + 42 * drift * Math.sin(rad * 0.9);
        const rx = 290 - t * 238;
        const ry = 215 - t * 183;

        // Luminance rises to a silver band a little inside the edge.
        const band = Math.pow(Math.sin(Math.PI * Math.min(1, t * 1.08)), 1.5);
        const tone = Math.round(58 + band * 186);

        list.push({
            cx: Number(cx.toFixed(2)),
            cy: Number(cy.toFixed(2)),
            rx: Number(rx.toFixed(2)),
            ry: Number(ry.toFixed(2)),
            rot: Number(rot.toFixed(2)),
            tone,
            width: Number((0.85 + 0.55 * band).toFixed(2)),
            opacity: Number((0.55 + 0.45 * band).toFixed(2)),
        });
    }

    return list;
});

// Innermost ~26 contours + the black center form the core; it drifts a
// little further than the outer ribbons for a soft depth separation.
const coreContours = computed<Contour[]>(() => contours.value.slice(-26));
const outerContours = computed<Contour[]>(() => contours.value.slice(0, -26));

const finePointer = useMediaQuery(
    '(hover: hover) and (pointer: fine) and (prefers-reduced-motion: no-preference)',
);

const onPointerMove = (event: PointerEvent) => {
    if (event.pointerType !== 'mouse' && event.pointerType !== 'pen') {
        return;
    }
    if (!finePointer.value) {
        return;
    }
    const svg = event.currentTarget as SVGSVGElement;
    const rect = svg.getBoundingClientRect();
    const x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
    const y = ((event.clientY - rect.top) / rect.height) * 2 - 1;
    svg.style.setProperty('--aperture-x', x.toFixed(3));
    svg.style.setProperty('--aperture-y', y.toFixed(3));
};

const onPointerEnd = (event: PointerEvent) => {
    const svg = event.currentTarget as SVGSVGElement;
    svg.style.setProperty('--aperture-x', '0');
    svg.style.setProperty('--aperture-y', '0');
};
</script>

<template>
    <svg
        viewBox="0 0 840 640"
        class="aperture-study block h-auto w-full"
        aria-hidden="true"
        xmlns="http://www.w3.org/2000/svg"
        @pointermove="onPointerMove"
        @pointerleave="onPointerEnd"
        @pointercancel="onPointerEnd"
    >
        <defs>
            <radialGradient id="aperture-glow" cx="46%" cy="44%" r="62%">
                <stop offset="0%" stop-color="#F4F4F4" stop-opacity="0.16" />
                <stop offset="55%" stop-color="#A6A6A6" stop-opacity="0.05" />
                <stop offset="100%" stop-color="#080808" stop-opacity="0" />
            </radialGradient>
        </defs>

        <rect width="840" height="640" fill="var(--pf-plate, #141414)" />

        <g class="aperture-glow-shift">
            <ellipse
                cx="420"
                cy="310"
                rx="360"
                ry="265"
                fill="url(#aperture-glow)"
            />
        </g>

        <g class="aperture-iris">
            <ellipse
                v-for="(c, i) in outerContours"
                :key="i"
                :cx="c.cx"
                :cy="c.cy"
                :rx="c.rx"
                :ry="c.ry"
                :transform="`rotate(${c.rot} ${c.cx} ${c.cy})`"
                fill="none"
                :stroke="`rgb(${c.tone}, ${c.tone}, ${c.tone})`"
                :stroke-width="c.width"
                :stroke-opacity="c.opacity"
            />

            <g class="aperture-core">
                <ellipse
                    v-for="(c, i) in coreContours"
                    :key="i"
                    :cx="c.cx"
                    :cy="c.cy"
                    :rx="c.rx"
                    :ry="c.ry"
                    :transform="`rotate(${c.rot} ${c.cx} ${c.cy})`"
                    fill="none"
                    :stroke="`rgb(${c.tone}, ${c.tone}, ${c.tone})`"
                    :stroke-width="c.width"
                    :stroke-opacity="c.opacity"
                />

                <ellipse
                    cx="428"
                    cy="304"
                    rx="46"
                    ry="28"
                    transform="rotate(-122 428 304)"
                    fill="#080808"
                />
            </g>
        </g>
    </svg>
</template>

<style scoped>
.aperture-glow-shift,
.aperture-iris,
.aperture-core {
    transform-box: fill-box;
    transition: transform 280ms ease-out;
}

.aperture-glow-shift {
    transform: translate(
        calc(var(--aperture-x, 0) * -10px),
        calc(var(--aperture-y, 0) * -10px)
    );
}

.aperture-iris {
    transform: translate(
        calc(var(--aperture-x, 0) * 8px),
        calc(var(--aperture-y, 0) * 6px)
    );
}

.aperture-core {
    transform: translate(
        calc(var(--aperture-x, 0) * 3px),
        calc(var(--aperture-y, 0) * 2px)
    );
}

@media (hover: hover) and (pointer: fine) and (prefers-reduced-motion: no-preference) {
    .aperture-study:hover
        :is(.aperture-glow-shift, .aperture-iris, .aperture-core) {
        transition-duration: 140ms;
    }
}

@media (prefers-reduced-motion: reduce) {
    .aperture-glow-shift,
    .aperture-iris,
    .aperture-core {
        transition: none;
        transform: none;
    }
}
</style>
