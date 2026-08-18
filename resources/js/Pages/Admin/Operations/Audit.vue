<script setup lang="ts">
import { ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t } from '@/lib/i18n';
import AppPagination from '@/Components/ui/AppPagination.vue';

interface Entry {
    id: number;
    action: string;
    severity: string;
    actor: string | null;
    subject_type: string | null;
    subject_id: number | null;
    result: string | null;
    request_id: string | null;
    created_at: string | null;
    context: Record<string, unknown> | null;
}

const props = defineProps<{
    entries: { data: Entry[]; links: Array<{ url: string | null; label: string; active: boolean }> };
    filters: { action: string; severity: string; actor: string; subject_type: string };
    facets: { actions: string[]; severities: string[]; subject_types: string[] };
}>();

const filters = ref({ ...props.filters });
const expanded = ref<number | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get('/admin/operations/audit', filters.value, { preserveState: true, replace: true });
    }, 300);
}, { deep: true });

const tone = (severity: string): string => ({
    critical: 'bg-negative/10 text-negative',
    warning: 'bg-caution/10 text-caution',
    notice: 'bg-brand/10 text-brand',
    info: 'bg-surface-sunken text-ink-muted',
}[severity] ?? 'bg-surface-sunken text-ink-muted');
</script>

<template>
    <Head :title="t('nav.system.audit')" />

    <AdminLayout>
        <template #title>{{ t('nav.system.audit') }}</template>

        <AppAlert variant="info" class="mb-6">
            {{ t('operations.audit.append_only_notice') }}
        </AppAlert>

        <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <label for="action" class="mh-label mb-1 block">{{ t('operations.audit.action') }}</label>
                <select
                    id="action" v-model="filters.action"
                    class="w-full rounded-card border border-line bg-surface-raised px-3 py-2 text-sm text-ink
                           focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                >
                    <option value="">{{ t('app.actions.filter') }}</option>
                    <option v-for="a in facets.actions" :key="a" :value="a">{{ a }}</option>
                </select>
            </div>

            <div>
                <label for="severity" class="mh-label mb-1 block">{{ t('operations.audit.severity') }}</label>
                <select
                    id="severity" v-model="filters.severity"
                    class="w-full rounded-card border border-line bg-surface-raised px-3 py-2 text-sm text-ink
                           focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                >
                    <option value="">{{ t('app.actions.filter') }}</option>
                    <option v-for="s in facets.severities" :key="s" :value="s">{{ s }}</option>
                </select>
            </div>

            <div>
                <label for="subject" class="mh-label mb-1 block">{{ t('operations.audit.subject') }}</label>
                <select
                    id="subject" v-model="filters.subject_type"
                    class="w-full rounded-card border border-line bg-surface-raised px-3 py-2 text-sm text-ink
                           focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                >
                    <option value="">{{ t('app.actions.filter') }}</option>
                    <option v-for="s in facets.subject_types" :key="s" :value="s">{{ s }}</option>
                </select>
            </div>

            <div>
                <label for="actor" class="mh-label mb-1 block">{{ t('operations.audit.actor') }}</label>
                <input
                    id="actor" v-model="filters.actor" type="search"
                    class="w-full rounded-card border border-line bg-surface-raised px-3 py-2 text-sm text-ink
                           focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                >
            </div>
        </div>

        <AppEmptyState
            v-if="entries.data.length === 0"
            :title="t('operations.audit.empty')"
            :description="t('operations.audit.empty_hint')"
        />

        <div v-else class="mh-card overflow-hidden">
            <ul class="divide-y divide-line">
                <li v-for="entry in entries.data" :key="entry.id">
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 px-5 py-3 text-start transition-colors
                               hover:bg-surface-sunken focus-visible:outline-none focus-visible:ring-2
                               focus-visible:ring-accent"
                        :aria-expanded="expanded === entry.id"
                        @click="expanded = expanded === entry.id ? null : entry.id"
                    >
                        <span :class="['shrink-0 rounded-full px-2 py-0.5 text-xs', tone(entry.severity)]">
                            {{ entry.severity }}
                        </span>

                        <div class="min-w-0 flex-1">
                            <p class="truncate font-mono text-sm text-ink">{{ entry.action }}</p>
                            <p class="mt-0.5 truncate text-xs text-ink-muted">
                                {{ entry.actor ?? t('operations.audit.system') }}
                                <span v-if="entry.subject_type">
                                    · {{ entry.subject_type }}<span v-if="entry.subject_id" class="numeral">#{{ entry.subject_id }}</span>
                                </span>
                            </p>
                        </div>

                        <time
                            v-if="entry.created_at" :datetime="entry.created_at"
                            class="numeral shrink-0 text-xs text-ink-faint"
                        >
                            {{ new Date(entry.created_at).toLocaleString() }}
                        </time>
                    </button>

                    <div v-if="expanded === entry.id" class="border-t border-line bg-surface-sunken px-5 py-4">
                        <!-- Context was scrubbed by Redactor on write. It is
                             never re-derived here, because re-deriving would
                             mean holding the unredacted value to derive from. -->
                        <p class="mh-label mb-2">{{ t('operations.audit.context') }}</p>
                        <pre class="overflow-x-auto text-xs text-ink-muted" dir="ltr">{{ JSON.stringify(entry.context ?? {}, null, 2) }}</pre>
                        <p v-if="entry.request_id" class="numeral mt-3 text-xs text-ink-faint" dir="ltr">
                            {{ t('operations.audit.request_id') }}: {{ entry.request_id }}
                        </p>
                    </div>
                </li>
            </ul>
        </div>

        <AppPagination :links="entries.links" />
    </AdminLayout>
</template>
