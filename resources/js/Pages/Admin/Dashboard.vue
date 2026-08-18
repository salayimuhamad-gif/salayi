<script setup lang="ts">
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t } from '@/lib/i18n';

const props = defineProps<{
    scheduler: { last_success_at: string | null; is_stale: boolean; consecutive_failures: number };
    build: { version: string; schema_version: number; environment: string; debug: boolean };
    activity: Record<string, number> | null;
}>();

/* Ordered so the numbers read as a story: presence, then growth, then reach. */
const activityOrder = [
    'online_now', 'active_today', 'active_week', 'active_month',
    'new_today', 'new_week', 'new_month', 'telegram_linked', 'total',
] as const;

const lastRun = computed(() =>
    props.scheduler.last_success_at
        ? new Date(props.scheduler.last_success_at).toLocaleString()
        : t('app.states.empty'),
);
</script>

<template>
    <Head :title="t('nav.overview')" />

    <AdminLayout>
        <template #title>{{ t('nav.overview') }}</template>

        <!--
          Operational truth before vanity counts. A silently stopped cron is the
          most common shared-hosting incident and nothing else on this page
          would reveal it, so it leads.
        -->
        <AppAlert v-if="scheduler.is_stale" variant="danger" class="mb-6">
            {{ t('admin.scheduler.stale') }}
        </AppAlert>

        <AppAlert v-if="build.debug && build.environment === 'production'" variant="danger" class="mb-6">
            {{ t('admin.warnings.debug_in_production') }}
        </AppAlert>

        <!-- Member activity: rendered only when the server sent it, which it
             does only for holders of the users permission. -->
        <AppCard v-if="activity" :title="t('admin.activity.title')" class="mb-5">
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3 lg:grid-cols-5">
                <div v-for="key in activityOrder" :key="key">
                    <dt class="text-xs text-ink-muted">{{ t(`admin.activity.${key}`) }}</dt>
                    <dd class="numeral mt-0.5 font-display text-lg font-semibold text-ink">
                        {{ activity[key] ?? 0 }}
                    </dd>
                </div>
            </dl>
        </AppCard>

        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <AppCard :title="t('admin.scheduler.title')">
                <p class="text-sm text-ink-muted">{{ t('admin.scheduler.last_success') }}</p>
                <p class="numeral mt-1 font-display text-lg font-semibold" :class="scheduler.is_stale ? 'text-negative' : 'text-positive'">
                    {{ lastRun }}
                </p>
                <p v-if="scheduler.consecutive_failures > 0" class="mt-2 text-xs text-negative">
                    {{ t('admin.scheduler.failures') }}:
                    <span class="numeral">{{ scheduler.consecutive_failures }}</span>
                </p>
            </AppCard>

            <AppCard :title="t('admin.build.title')">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-muted">{{ t('admin.build.version') }}</dt>
                        <dd class="numeral font-medium text-ink">{{ build.version }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-muted">{{ t('admin.build.schema') }}</dt>
                        <dd class="numeral font-medium text-ink">{{ build.schema_version }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-ink-muted">{{ t('admin.build.environment') }}</dt>
                        <dd class="font-medium text-ink">{{ build.environment }}</dd>
                    </div>
                </dl>
            </AppCard>

            <AppCard :title="t('admin.slice.title')">
                <p class="text-sm text-ink-muted">{{ t('admin.slice.description') }}</p>
            </AppCard>
        </div>
    </AdminLayout>
</template>
