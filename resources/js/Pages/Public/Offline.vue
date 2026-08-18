<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { useLocale } from '@/Composables/useLocale';

const { current } = useLocale();

/*
 * Copy is inlined rather than read from the translation files because this
 * page is served by the service worker from cache, with no server round trip
 * and therefore no shared Inertia payload to translate from.
 */
const copy: Record<string, { title: string; body: string; action: string }> = {
    ckb: {
        title: 'بێ ئینتەرنێت',
        body: 'ناتوانرێت پەیوەندی بکرێت. ئەو پەڕانەی پێشتر کردوونەتەوە هێشتا بەردەستن.',
        action: 'دووبارە هەوڵدانەوە',
    },
    ar: {
        title: 'غير متصل بالإنترنت',
        body: 'تعذّر الاتصال. الصفحات التي فتحتها سابقًا لا تزال متاحة.',
        action: 'إعادة المحاولة',
    },
    en: {
        title: 'Offline',
        body: 'No connection. Pages you opened earlier are still available.',
        action: 'Retry',
    },
};

const text = copy[current.value] ?? copy.en;

/*
 * A Vue template resolves identifiers against the component instance, so the
 * global `window` is not reachable from markup. Written inline in Step 1 and
 * only caught by the first real type check — php -l cannot see a .vue file.
 */
function reload(): void {
    window.location.reload();
}
</script>

<template>
    <Head :title="text.title" />

    <div class="flex min-h-full items-center justify-center px-4">
        <div class="mh-panel max-w-md text-center">
            <h1 class="font-display text-xl font-bold text-ink">{{ text.title }}</h1>
            <p class="mt-2 text-ink-muted">{{ text.body }}</p>
            <button
                type="button"
                class="mt-5 rounded-card bg-brand px-4 py-2 text-sm font-medium text-white"
                @click="reload"
            >
                {{ text.action }}
            </button>
        </div>
    </div>
</template>
