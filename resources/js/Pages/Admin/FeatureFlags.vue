<script setup lang="ts">
import { computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppToggle from '@/Components/ui/AppToggle.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import { t } from '@/lib/i18n';
import type { SharedPageProps } from '@/Types/inertia';

interface Flag {
    flag: string;
    enabled: boolean;
    requires_super_admin: boolean;
    known: boolean;
    group: string;
}

const props = defineProps<{ flags: Flag[] }>();
const page = usePage<SharedPageProps>();

const isSuperAdmin = computed(() => page.props.auth.permissions.length > 0 && page.props.auth.user?.is_admin === true);

const grouped = computed(() => {
    const groups: Record<string, Flag[]> = {};
    for (const flag of props.flags) {
        (groups[flag.group] ??= []).push(flag);
    }
    return groups;
});

function toggle(flag: Flag, value: boolean): void {
    router.put('/admin/features', { flag: flag.flag, enabled: value }, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('nav.features')" />

    <AdminLayout>
        <template #title>{{ t('nav.features') }}</template>

        <AppAlert variant="info" class="mb-6">
            {{ t('admin.features.explanation') }}
        </AppAlert>

        <AppEmptyState
            v-if="flags.length === 0"
            :title="t('admin.features.none')"
            :description="t('admin.features.none_hint')"
        />

        <div v-else class="space-y-5">
            <AppCard v-for="(items, group) in grouped" :key="group" :title="group">
                <ul class="divide-y divide-line">
                    <li v-for="flag in items" :key="flag.flag">
                        <AppToggle
                            :model-value="flag.enabled"
                            :label="flag.flag"
                            :description="flag.requires_super_admin
                                ? t('admin.features.super_admin_only')
                                : (flag.known ? undefined : t('admin.features.unknown_flag'))"
                            :disabled="flag.requires_super_admin && !isSuperAdmin"
                            @update:model-value="(value: boolean) => toggle(flag, value)"
                        />
                    </li>
                </ul>
            </AppCard>
        </div>
    </AdminLayout>
</template>
