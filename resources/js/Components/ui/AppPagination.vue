<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { t } from '@/lib/i18n';

/*
 * Laravel paginator links, rendered as text.
 *
 * Four admin pages each carried their own copy of this markup, all four using
 * `v-html="link.label"`. That was flagged twice over by lint — once because
 * v-html on a component replaces the component's own content, and once as an
 * XSS vector — and it sat badly beside the discipline stated elsewhere in this
 * codebase (see Notifications/Show.vue, where v-html is explicitly refused for
 * exactly this reason).
 *
 * The only reason v-html was ever needed is that Laravel's paginator emits
 * labels containing HTML entities (&laquo; Previous). Decoding that small,
 * closed set here gives the same visible output through text interpolation,
 * so the directive disappears rather than being suppressed.
 *
 * The decoder is deliberately a fixed map rather than a DOM round-trip
 * (innerHTML on a detached element): a table cannot execute anything, and it
 * works under SSR where there is no document.
 */
export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = withDefaults(
    defineProps<{
        links: PaginationLink[];
        /** Inertia SPA navigation is not always wanted — Prices/Audit use plain hrefs. */
        spa?: boolean;
    }>(),
    { spa: false },
);

const ENTITIES: Record<string, string> = {
    '&laquo;': '«',
    '&raquo;': '»',
    '&lsaquo;': '‹',
    '&rsaquo;': '›',
    '&hellip;': '…',
    '&nbsp;': ' ',
    '&amp;': '&',
    '&lt;': '<',
    '&gt;': '>',
    '&quot;': '"',
    '&#039;': "'",
};

function decode(label: string): string {
    return label
        .replace(/&[a-z]+;|&#0?39;/gi, (match) => ENTITIES[match.toLowerCase()] ?? match)
        .replace(/&#(\d+);/g, (_match, code: string) => String.fromCodePoint(Number(code)))
        // Any tag that survived the entity pass is stripped rather than rendered.
        .replace(/<[^>]*>/g, '')
        .trim();
}

const items = computed(() =>
    props.links.map((link) => ({ ...link, text: decode(link.label) })),
);

// Laravel always emits at least Previous / page 1 / Next, so three links means
// a single page and nothing worth showing.
const visible = computed(() => props.links.length > 3);
</script>

<template>
    <nav v-if="visible" class="mt-6 flex flex-wrap gap-1" :aria-label="t('app.actions.next')">
        <component
            :is="spa ? Link : 'a'"
            v-for="link in items"
            :key="link.label"
            :href="link.url ?? '#'"
            class="rounded-card px-3 py-1.5 text-sm transition-colors
                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
            :class="[
                link.active ? 'bg-brand text-white' : 'text-ink-muted hover:bg-surface-sunken',
                link.url ? '' : 'pointer-events-none opacity-40',
            ]"
            :aria-current="link.active ? 'page' : undefined"
            :aria-disabled="link.url ? undefined : 'true'"
        >
            {{ link.text }}
        </component>
    </nav>
</template>
