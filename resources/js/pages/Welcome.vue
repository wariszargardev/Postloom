<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    CalendarDays,
    Clock,
    Images,
    Layers,
    RefreshCw,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import PlatformIcon from '@/components/PlatformIcon.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { dashboard, login, register } from '@/routes';

const page = usePage();
const isLoggedIn = computed(() => Boolean(page.props.auth?.user));

const platforms = [
    { provider: 'linkedin', label: 'LinkedIn', live: true },
    { provider: 'youtube', label: 'YouTube', live: true },
    { provider: 'facebook', label: 'Facebook', live: true },
    { provider: 'instagram', label: 'Instagram', live: false },
    { provider: 'tiktok', label: 'TikTok', live: true },
    { provider: 'whatsapp', label: 'WhatsApp', live: false },
];

const features = [
    {
        icon: CalendarDays,
        title: 'Calendar',
        text: 'See every post by day, week or month. Drag a post to move it.',
    },
    {
        icon: RefreshCw,
        title: 'Reliable scheduler',
        text: 'Posts go out on time, retry on hiccups and never double-post.',
    },
    {
        icon: Images,
        title: 'Media library',
        text: 'Images, videos, PDFs and audio. Shorts, Reels and thumbnails included.',
    },
    {
        icon: Layers,
        title: 'Workspaces',
        text: 'Group brands and clients. Connect many accounts per platform.',
    },
];
</script>

<template>
    <Head title="Write once, post everywhere" />

    <div class="bg-grid min-h-screen bg-background text-foreground">
        <header
            class="mx-auto flex w-full max-w-6xl items-center justify-between px-4 py-5 md:px-8"
        >
            <Link
                :href="isLoggedIn ? dashboard() : login()"
                class="flex items-center gap-2.5"
            >
                <AppLogoIcon class="size-9" />
                <span class="font-mono text-lg font-bold tracking-tight"
                    >Post<span class="text-brand">loom</span></span
                >
            </Link>
            <nav class="flex items-center gap-2">
                <Link
                    v-if="isLoggedIn"
                    :href="dashboard()"
                    class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground transition-opacity hover:opacity-90"
                    data-test="nav-dashboard"
                    >Open dashboard <ArrowRight class="size-4"
                /></Link>
                <template v-else>
                    <Link
                        :href="login()"
                        class="inline-flex h-9 items-center rounded-lg px-4 text-sm font-semibold hover:text-brand"
                        data-test="nav-login"
                        >Log in</Link
                    >
                    <Link
                        v-if="$page.props.canRegister"
                        :href="register()"
                        class="inline-flex h-9 items-center rounded-lg border px-4 text-sm font-semibold hover:border-brand/50"
                        data-test="nav-register"
                        >Register</Link
                    >
                </template>
            </nav>
        </header>

        <main class="mx-auto w-full max-w-6xl px-4 md:px-8">
            <section
                class="grid items-center gap-10 py-12 md:py-20 lg:grid-cols-[minmax(0,1fr)_420px]"
            >
                <div class="flex flex-col gap-6">
                    <span
                        class="w-fit rounded-full border border-amber/40 bg-amber/10 px-3 py-1 font-mono text-xs font-semibold text-amber"
                        >Social scheduler · Build Real Code</span
                    >
                    <h1
                        class="text-4xl leading-[1.05] font-extrabold tracking-tight md:text-6xl"
                    >
                        Write once,<br />
                        <span class="text-brand">post everywhere.</span>
                    </h1>
                    <p class="max-w-xl text-lg text-muted-foreground">
                        Schedule LinkedIn, YouTube and Facebook posts from one
                        calendar, across all your workspaces. Postloom uploads
                        the media, posts on time and tells you when something
                        needs a look.
                    </p>
                    <div class="flex flex-wrap items-center gap-3">
                        <Link
                            :href="isLoggedIn ? dashboard() : login()"
                            class="inline-flex h-11 items-center gap-2 rounded-lg bg-primary px-6 font-semibold text-primary-foreground transition-opacity hover:opacity-90"
                            data-test="hero-cta"
                            >{{ isLoggedIn ? 'Open dashboard' : 'Log in' }}
                            <ArrowRight class="size-4"
                        /></Link>
                        <span class="font-mono text-sm text-muted-foreground"
                            >Runs on your own PC.</span
                        >
                    </div>
                </div>

                <div class="surface flex flex-col gap-4 p-5" aria-hidden="true">
                    <div class="flex items-center gap-1.5">
                        <span class="size-2.5 rounded-full bg-status-failed" />
                        <span class="size-2.5 rounded-full bg-amber" />
                        <span class="size-2.5 rounded-full bg-brand" />
                        <span
                            class="ml-auto font-mono text-xs text-muted-foreground"
                            >post · #42</span
                        >
                    </div>
                    <div class="flex items-center gap-3">
                        <div
                            class="flex size-10 items-center justify-center rounded-full bg-brand/15 font-mono text-sm font-bold text-brand"
                        >
                            BR
                        </div>
                        <div class="flex flex-col">
                            <span class="font-semibold">Build Real Code</span>
                            <span
                                class="flex items-center gap-1 font-mono text-xs text-muted-foreground"
                                ><Clock class="size-3" /> Thu 9:00 AM</span
                            >
                        </div>
                    </div>
                    <p class="text-sm leading-relaxed">
                        Validated data only — never pass
                        <code
                            class="rounded bg-muted px-1 py-0.5 font-mono text-xs text-code-blue"
                            >$request-&gt;all()</code
                        >
                        into
                        <code
                            class="rounded bg-muted px-1 py-0.5 font-mono text-xs text-code-blue"
                            >create()</code
                        >. <span class="text-brand">#Laravel #PHP</span>
                    </p>
                    <div class="flex flex-col gap-2 border-t pt-4">
                        <div class="flex items-center justify-between text-sm">
                            <span class="flex items-center gap-2"
                                ><PlatformIcon provider="linkedin" />
                                LinkedIn</span
                            >
                            <StatusBadge status="published" label="Published" />
                        </div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="flex items-center gap-2"
                                ><PlatformIcon provider="youtube" /> YouTube
                                Short</span
                            >
                            <StatusBadge
                                status="publishing"
                                label="Publishing"
                            />
                        </div>
                        <div class="flex items-center justify-between text-sm">
                            <span class="flex items-center gap-2"
                                ><PlatformIcon provider="facebook" /> Facebook
                                Reel</span
                            >
                            <StatusBadge status="scheduled" label="Scheduled" />
                        </div>
                    </div>
                </div>
            </section>

            <section class="flex flex-col gap-4 pb-12">
                <h2
                    class="font-mono text-xs font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    Platforms
                </h2>
                <ul class="flex flex-wrap gap-2">
                    <li
                        v-for="platform in platforms"
                        :key="platform.provider"
                        class="inline-flex items-center gap-2 rounded-full border bg-card/60 py-1.5 pr-3 pl-1.5 text-sm"
                        :class="platform.live ? '' : 'opacity-60'"
                    >
                        <PlatformIcon :provider="platform.provider" size="md" />
                        <span class="font-medium">{{ platform.label }}</span>
                        <span
                            class="font-mono text-[0.65rem] font-bold uppercase"
                            :class="
                                platform.live
                                    ? 'text-brand'
                                    : 'text-muted-foreground'
                            "
                            >{{ platform.live ? 'Live' : 'Soon' }}</span
                        >
                    </li>
                </ul>
            </section>

            <section class="grid gap-4 pb-16 sm:grid-cols-2 lg:grid-cols-4">
                <article
                    v-for="feature in features"
                    :key="feature.title"
                    class="surface flex flex-col gap-3 p-5"
                >
                    <span
                        class="flex size-10 items-center justify-center rounded-lg bg-brand/10 text-brand ring-1 ring-brand/25"
                    >
                        <component :is="feature.icon" class="size-5" />
                    </span>
                    <h3 class="font-bold">{{ feature.title }}</h3>
                    <p class="text-sm text-muted-foreground">
                        {{ feature.text }}
                    </p>
                </article>
            </section>
        </main>

        <footer class="border-t">
            <div
                class="mx-auto flex w-full max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-6 font-mono text-xs text-muted-foreground md:px-8"
            >
                <span>Built for Build Real Code</span>
                <span>Write once, post everywhere.</span>
            </div>
        </footer>
    </div>
</template>
