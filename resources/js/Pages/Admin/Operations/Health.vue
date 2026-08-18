<script setup lang="ts">
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t } from '@/lib/i18n';

const props = defineProps<{
    scheduler: Array<{ key: string; last_success_at: string | null; consecutive_failures: number; is_stale: boolean }>;
    queue: { driver: string; pending: Record<string, number>; oldest_seconds: number | null };
    failures: { total: number; recent: Array<{ id: number; queue: string; job: string; failed_at: string; exception: string }> };
    data_quality: Record<string, number>;
}>();

const schedulerStale = computed(() => props.scheduler.some((h) => h.is_stale));

// Only non-zero gaps are worth a reader's attention; a wall of zeroes trains
// people to stop looking.
const gaps = computed(() =>
    Object.entries(props.data_quality).filter(([, count]) => count > 0),
);

const queueEntries = computed(() => Object.entries(props.queue.pending));
</script>

<template>
    <Head :title="t('nav.system.health')" />

    <AdminLayout>
        <template #title>{{ t('nav.system.health') }}</template>

        <!-- A silently stopped cron is the most common shared-hosting incident
             and nothing else on any page reveals it. -->
        <AppAlert v-if="schedulerStale" variant="danger" class="mb-6">
            {{ t('admin.scheduler.stale') }}
        </AppAlert>

        <AppAlert v-if="failures.total > 0" variant="warning" class="mb-6">
            {{ t('operations.health.failures_notice') }}
        </AppAlert>

        <div class="grid gap-5 lg:grid-cols-2">
            <AppCard :title="t('admin.scheduler.title')">
                <ul v-if="scheduler.length > 0" class="divide-y divide-line">
                    <li v-for="h in scheduler" :key="h.key" class="flex items-center justify-between gap-3 py-2.5">
                        <span class="font-mono text-sm text-ink">{{ h.key }}</span>
                        <span class="numeral text-xs" :class="h.is_stale ? 'text-negative' : 'text-positive'">
                            {{ h.last_success_at ? new Date(h.last_success_at).toLocaleString() : t('app.states.empty') }}
                        </span>
                    </li>
                </ul>
                <p v-else class="text-sm text-ink-muted">{{ t('operations.health.no_heartbeat') }}</p>
            </AppCard>

            <AppCard :title="t('operations.health.queue')" :description="queue.driver">
                <ul v-if="queueEntries.length > 0" class="divide-y divide-line">
                    <li
                        v-for="[name, count] in queueEntries" :key="name"
                        class="flex items-center justify-between gap-3 py-2.5"
                    >
                        <span class="font-mono text-sm text-ink">{{ name }}</span>
                        <span class="numeral text-sm text-ink-muted">{{ count }}</span>
                    </li>
                </ul>
                <p v-else class="text-sm text-ink-muted">{{ t('operations.health.queue_empty') }}</p>

                <p
                    v-if="queue.oldest_seconds !== null && queue.oldest_seconds > 300"
                    class="numeral mt-4 text-xs text-caution"
                >
                    {{ t('operations.health.queue_backed_up') }}
                </p>
            </AppCard>
        </div>

        <AppCard v-if="gaps.length > 0" :title="t('operations.health.data_quality')" class="mt-5">
            <p class="mb-4 text-xs text-ink-faint">{{ t('operations.health.data_quality_hint') }}</p>
            <ul class="divide-y divide-line">
                <li
                    v-for="[key, count] in gaps" :key="key"
                    class="flex items-center justify-between gap-3 py-2.5"
                >
                    <span class="text-sm text-ink">{{ t(`operations.gaps.${key}`) }}</span>
                    <span class="numeral text-sm font-medium text-caution">{{ count }}</span>
                </li>
            </ul>
        </AppCard>

        <AppCard v-if="failures.recent.length > 0" :title="t('operations.health.failed_jobs')" class="mt-5">
            <ul class="divide-y divide-line">
                <li v-for="failure in failures.recent" :key="failure.id" class="py-3">
                    <div class="flex items-center justify-between gap-3">
                        <span class="truncate font-mono text-sm text-ink">{{ failure.job }}</span>
                        <span class="numeral shrink-0 text-xs text-ink-faint">{{ failure.failed_at }}</span>
                    </div>
                    <!-- Truncated on the server. A full stack trace on an admin
                         page discloses paths, versions and sometimes query
                         fragments containing user data. -->
                    <pre class="mt-1.5 overflow-x-auto text-xs text-ink-muted" dir="ltr">{{ failure.exception }}</pre>
                </li>
            </ul>
        </AppCard>
    </AdminLayout>
</template>
