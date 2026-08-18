<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t } from '@/lib/i18n';

const props = defineProps<{
    preferences: { frequency: 'immediate' | 'daily'; digest_hour: number };
    options: {
        frequencies: Array<{ value: string; label: string }>;
        hours: Array<{ value: number; label: string }>;
    };
    never_batched_notice: string;
}>();

const frequency = ref(props.preferences.frequency);
const digestHour = ref(props.preferences.digest_hour);
const saving = ref(false);

// The hour only means something on the digest, so it appears only then rather
// than sitting disabled — a control that is visible but inert reads as broken.
const showsHour = computed(() => frequency.value === 'daily');

function save(): void {
    saving.value = true;
    router.post('/admin/notifications/preferences', {
        frequency: frequency.value,
        digest_hour: digestHour.value,
    }, {
        preserveScroll: true,
        onFinish: () => { saving.value = false; },
    });
}
</script>

<template>
    <Head :title="t('notifications.preferences.title')" />

    <AdminLayout>
        <template #title>{{ t('notifications.preferences.title') }}</template>

        <Link
            href="/admin/notifications"
            class="mb-5 inline-flex items-center gap-1.5 text-sm text-ink-muted transition-colors
                   hover:text-ink focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
        >
            <svg
                class="h-4 w-4 rtl:-scale-x-100" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2" aria-hidden="true"
            >
                <path d="m15 18-6-6 6-6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            {{ t('notifications.center.back') }}
        </Link>

        <div class="mh-card max-w-xl p-5 sm:p-7">
            <p class="text-sm leading-relaxed text-ink-muted">
                {{ t('notifications.preferences.description') }}
            </p>

            <fieldset class="mt-6">
                <legend class="mh-label mb-2">{{ t('notifications.preferences.frequency') }}</legend>

                <div class="space-y-2">
                    <label
                        v-for="option in options.frequencies"
                        :key="option.value"
                        class="flex cursor-pointer items-start gap-3 rounded-card border p-3.5 transition-colors"
                        :class="frequency === option.value
                            ? 'border-brand bg-brand/[0.04]'
                            : 'border-line hover:bg-surface-sunken'"
                    >
                        <input
                            v-model="frequency"
                            type="radio"
                            name="frequency"
                            :value="option.value"
                            class="mt-0.5 h-4 w-4 shrink-0 accent-brand
                                   focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                        >
                        <span class="text-sm text-ink">{{ option.label }}</span>
                    </label>
                </div>
            </fieldset>

            <div v-if="showsHour" class="mt-5">
                <label for="digest_hour" class="mh-label mb-1 block">
                    {{ t('notifications.preferences.digest_hour') }}
                </label>
                <select
                    id="digest_hour"
                    v-model.number="digestHour"
                    class="numeral w-full max-w-[10rem] rounded-card border border-line bg-surface-raised
                           px-3 py-2 text-sm text-ink focus:border-brand focus:outline-none
                           focus:ring-2 focus:ring-accent"
                    dir="ltr"
                >
                    <option v-for="hour in options.hours" :key="hour.value" :value="hour.value">
                        {{ hour.label }}
                    </option>
                </select>
            </div>

            <!-- Stated before the choice is made, not discovered afterwards.
                 Someone weighing the digest deserves to know it will not delay
                 a rejection or a security notice. -->
            <AppAlert variant="info" class="mt-6">
                {{ never_batched_notice }}
            </AppAlert>

            <AppButton variant="primary" class="mt-6" :loading="saving" @click="save">
                {{ t('notifications.preferences.save') }}
            </AppButton>
        </div>
    </AdminLayout>
</template>
