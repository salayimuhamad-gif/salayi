<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t } from '@/lib/i18n';

defineProps<{ codes: string[] }>();
</script>

<template>
    <Head :title="t('identity.mfa.recovery_codes')" />

    <AuthLayout :title="t('identity.mfa.recovery_codes')">
        <!--
          Shown once, from the session. The codes are stored encrypted and are
          never rendered again — holding them decryptable somewhere a
          compromised session could reach would defeat their purpose.
        -->
        <AppAlert variant="warning" class="mb-6">
            {{ t('identity.mfa.recovery_notice') }}
        </AppAlert>

        <ul class="mb-6 grid grid-cols-2 gap-2 rounded-card border border-line bg-surface-sunken p-4">
            <li v-for="code in codes" :key="code" class="numeral font-mono text-sm text-ink">
                {{ code }}
            </li>
        </ul>

        <AppButton block @click="router.visit('/admin')">
            {{ t('identity.mfa.saved_codes') }}
        </AppButton>
    </AuthLayout>
</template>
