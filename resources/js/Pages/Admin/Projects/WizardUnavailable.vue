<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import { Link } from '@inertiajs/vue3';
import { t } from '@/lib/i18n';

/*
 * Feature-disabled and permission-denied, as a page rather than a bare 403.
 *
 * A visitor who followed a link and got a blank error cannot tell whether the
 * feature is off, they lack a permission, or something broke — and the three
 * need different actions from them.
 */
defineProps<{ reason: 'feature_disabled' | 'permission_denied' }>();
</script>

<template>
    <Head :title="t('projects.wizard.creation.wizard_button')" />

    <AdminLayout>
        <template #title>{{ t('projects.wizard.creation.wizard_button') }}</template>

        <AppAlert
            variant="warning"
            :message="reason === 'feature_disabled'
                ? t('projects.wizard.creation.state_disabled')
                : t('projects.wizard.creation.state_denied')"
        />

        <Link href="/admin/projects" class="mt-4 inline-block">
            <AppButton variant="secondary">{{ t('projects.index_title') }}</AppButton>
        </Link>
    </AdminLayout>
</template>
