<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Hand } from '@lucide/vue';
import { computed } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

const page = usePage();

const count = computed(() => page.props.postsWaitingForYou ?? 0);
const isOnToPost = computed(() => page.url.startsWith('/to-post'));
</script>

<template>
    <div
        v-if="count > 0 && !isOnToPost"
        class="px-4 pt-4"
        data-test="to-post-banner"
    >
        <Alert>
            <Hand class="size-4" />
            <AlertTitle>
                {{ count === 1 ? '1 post is' : `${count} posts are` }}
                waiting for you to upload
            </AlertTitle>
            <AlertDescription>
                <span>
                    The time has come for a post you chose to upload yourself.
                    <Link
                        href="/to-post"
                        class="font-medium underline underline-offset-4"
                        >Open To post</Link
                    >.
                </span>
            </AlertDescription>
        </Alert>
    </div>
</template>
