<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import ApertureStudy from '@/components/landing/ApertureStudy.vue';
import { home, login, register } from '@/routes';
import generations from '@/routes/generations';

const steps = [
    {
        number: '01',
        title: 'Describe',
        body: 'Write a prompt, then pick a size, a quality level and up to four optional reference links.',
    },
    {
        number: '02',
        title: 'Check the estimate',
        body: 'You see the estimated cost before anything is generated. The estimate includes a 25% markup, but it is not a cap on the final charge.',
    },
    {
        number: '03',
        title: 'Follow the result',
        body: 'History shows progress, the finished image, and the final charge. You are charged only if the generation succeeds.',
    },
];
</script>

<template>
    <Head title="Give the idea a frame">
        <meta
            name="description"
            content="PixelForge turns a prompt into an image. See an estimated cost before you generate, then find your image and final charge in your history."
        />
    </Head>

    <div class="landing flex min-h-screen flex-col">
        <header class="mx-auto w-full max-w-7xl px-5 pt-6 sm:px-10">
            <nav class="flex items-center gap-6">
                <Link
                    :href="home()"
                    class="flex items-center gap-2.5"
                    aria-label="PixelForge home"
                >
                    <AppLogoIcon class="size-5 text-[var(--pf-paper)]" />
                    <span class="text-sm font-medium text-[var(--pf-paper)]"
                        >PixelForge</span
                    >
                </Link>
                <div
                    class="ml-auto flex items-center gap-5 text-sm font-medium text-[var(--pf-muted)]"
                >
                    <a
                        href="#how-it-works"
                        class="transition-colors hover:text-[var(--pf-paper)]"
                        >How it works</a
                    >
                    <Link
                        v-if="$page.props.auth.user"
                        :href="generations.index()"
                        class="transition-colors hover:text-[var(--pf-paper)]"
                        >History</Link
                    >
                    <Link
                        v-else
                        :href="login()"
                        class="transition-colors hover:text-[var(--pf-paper)]"
                        >Log in</Link
                    >
                </div>
            </nav>
        </header>

        <main class="mx-auto flex w-full max-w-7xl grow flex-col px-5 sm:px-10">
            <section
                class="grid grow items-center gap-10 py-14 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)] lg:gap-8 lg:py-10"
            >
                <div class="max-w-xl">
                    <h1
                        class="text-[clamp(2.6rem,6vw,4.5rem)] leading-[1.02] font-semibold tracking-[-0.03em] text-[var(--pf-paper)]"
                    >
                        Give the idea<br class="hidden lg:block" />
                        a frame.
                    </h1>
                    <p
                        class="mt-6 max-w-[46ch] text-base leading-relaxed text-[var(--pf-muted)]"
                    >
                        Turn a prompt into an image. See an estimated cost
                        before you generate, then find your image and final
                        charge in your history.
                    </p>
                    <div class="mt-9">
                        <Link
                            v-if="$page.props.auth.user"
                            :href="generations.create()"
                            class="inline-flex items-center rounded-sm bg-[var(--pf-paper)] px-6 py-3 text-sm font-medium text-[var(--pf-ink)] transition-colors hover:bg-white"
                            >Open studio</Link
                        >
                        <Link
                            v-else
                            :href="register()"
                            class="inline-flex items-center rounded-sm bg-[var(--pf-paper)] px-6 py-3 text-sm font-medium text-[var(--pf-ink)] transition-colors hover:bg-white"
                            >Create an account</Link
                        >
                    </div>
                </div>

                <figure class="w-full min-w-0">
                    <ApertureStudy />
                    <figcaption class="mt-4 flex items-baseline gap-3 text-xs">
                        <span class="font-medium text-[var(--pf-paper)]"
                            >Aperture study</span
                        >
                        <span class="text-[var(--pf-muted)]">Illustration</span>
                    </figcaption>
                </figure>
            </section>

            <section
                id="how-it-works"
                class="scroll-mt-10 border-t border-[var(--pf-rule)] py-14"
                aria-labelledby="how-it-works-heading"
            >
                <h2
                    id="how-it-works-heading"
                    class="text-base font-semibold text-[var(--pf-paper)]"
                >
                    How it works
                </h2>
                <ol class="mt-8 grid gap-8 sm:grid-cols-3">
                    <li
                        v-for="step in steps"
                        :key="step.number"
                        class="border-t border-[var(--pf-rule)] pt-5"
                    >
                        <span
                            class="text-xs font-medium tracking-[0.18em] text-[var(--pf-muted)]"
                            >{{ step.number }}</span
                        >
                        <h3
                            class="mt-2 text-base font-semibold text-[var(--pf-paper)]"
                        >
                            {{ step.title }}
                        </h3>
                        <p
                            class="mt-2 text-sm leading-relaxed text-[var(--pf-muted)]"
                        >
                            {{ step.body }}
                        </p>
                    </li>
                </ol>
            </section>
        </main>

        <footer class="mx-auto w-full max-w-7xl px-5 pb-8 sm:px-10">
            <div
                class="flex items-center gap-2.5 border-t border-[var(--pf-rule)] pt-6"
            >
                <AppLogoIcon class="size-4 text-[var(--pf-muted)]" />
                <span class="text-xs font-medium text-[var(--pf-muted)]"
                    >PixelForge</span
                >
            </div>
        </footer>
    </div>
</template>

<style>
.landing {
    --pf-ink: #080808;
    --pf-plate: #141414;
    --pf-rule: #2b2b2b;
    --pf-muted: #a6a6a6;
    --pf-paper: #f4f4f4;

    background-color: var(--pf-ink);
    color: var(--pf-paper);
    font-weight: 400;
}

.landing :is(a, button, [tabindex]):focus-visible {
    outline: 2px solid var(--pf-paper);
    outline-offset: 3px;
}

@media (prefers-reduced-motion: no-preference) {
    html:has(.landing) {
        scroll-behavior: smooth;
    }
}
</style>
