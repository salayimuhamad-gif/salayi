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

/*
 * The real signal from the server, not a heuristic. The previous derivation
 * (permissions.length > 0 && is_admin) was true for EVERY administrative
 * user, so a System Admin saw the super-admin-only toggles as usable and got
 * a server-side refusal on click.
 */
const isSuperAdmin = computed(() => page.props.auth.user?.is_super_admin === true);

const grouped = computed(() => {
    const groups: Record<string, Flag[]> = {};
    for (const flag of props.flags) {
        (groups[flag.group] ??= []).push(flag);
    }
    return groups;
});

/*
 * Human names for the switches. "map.investment" tells an operator nothing
 * about what turning it on will do to the public site; the label and the
 * one-line description do, in the operator's own language. Unknown flags
 * (a row in the store the config no longer declares) fall back to the raw
 * key rather than hiding behind a missing translation.
 */
const flagLabel = (flag: Flag): string => {
    const key = `system.flags.${flag.flag}.label`;
    const value = t(key);
    return value === key ? flag.flag : value;
};

const flagDescription = (flag: Flag): string => {
    const parts: string[] = [flag.flag];

    const key = `system.flags.${flag.flag}.description`;
    const value = t(key);
    if (value !== key) parts.push(value);

    if (flag.requires_super_admin) parts.push(t('admin.features.super_admin_only'));
    if (!flag.known) parts.push(t('admin.features.unknown_flag'));

    return parts.join(' · ');
};

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
                            :label="flagLabel(flag)"
                            :description="flagDescription(flag)"
                            :disabled="flag.requires_super_admin && !isSuperAdmin"
                            @update:model-value="(value: boolean) => toggle(flag, value)"
                        />
                    </li>
                </ul>
            </AppCard>
        </div>
    </AdminLayout>
</template>
