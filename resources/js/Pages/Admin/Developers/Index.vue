<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import { t } from '@/lib/i18n';

interface Row {
    id: number;
    name: string;
    status: string;
    is_verified: boolean;
    project_count: number;
    missing_translations: string[];
}

defineProps<{
    developers: { data: Row[] };
    filters: { q: string };
    can: { create: boolean; verify: boolean };
}>();
</script>

<template>
    <Head :title="t('nav.projects.developers')" />

    <AdminLayout>
        <template #title>{{ t('nav.projects.developers') }}</template>

        <div class="mb-6 flex justify-end">
            <Link v-if="can.create" href="/admin/developers/create">
                <AppButton>{{ t('projects.developers.create') }}</AppButton>
            </Link>
        </div>

        <AppEmptyState
            v-if="developers.data.length === 0"
            :title="t('projects.developers.empty')"
            :description="t('projects.developers.empty_hint')"
        >
            <template v-if="can.create" #action>
                <Link href="/admin/developers/create">
                    <AppButton>{{ t('projects.developers.create') }}</AppButton>
                </Link>
            </template>
        </AppEmptyState>

        <div v-else class="mh-card overflow-hidden">
            <ul class="divide-y divide-line">
                <li v-for="developer in developers.data" :key="developer.id">
                    <Link
                        :href="`/admin/developers/${developer.id}/edit`"
                        class="flex items-center gap-4 px-5 py-4 transition-colors hover:bg-surface-sunken
                               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-ink">{{ developer.name }}</p>
                            <p class="numeral mt-0.5 text-xs text-ink-muted">
                                {{ developer.project_count }} {{ t('nav.projects') }}
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <!-- Verification is shown separately from publication:
                                 one says visible, the other says we checked. -->
                            <span
                                v-if="developer.is_verified"
                                class="rounded-full bg-positive/10 px-2.5 py-0.5 text-xs text-positive"
                            >{{ t('companies.verification.verified') }}</span>

                            <span
                                v-if="developer.missing_translations.length > 0"
                                class="rounded-full bg-caution/10 px-2 py-0.5 text-xs text-caution"
                            >{{ developer.missing_translations.join(' / ') }}</span>

                            <span
                                class="rounded-full px-2.5 py-0.5 text-xs"
                                :class="developer.status === 'published'
                                    ? 'bg-positive/10 text-positive'
                                    : 'bg-surface-sunken text-ink-muted'"
                            >{{ t(`projects.publication_statuses.${developer.status}`) }}</span>
                        </div>
                    </Link>
                </li>
            </ul>
        </div>
    </AdminLayout>
</template>
