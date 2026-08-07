<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import AppToggle from '@/Components/ui/AppToggle.vue';
import { t } from '@/lib/i18n';

const props = defineProps<{
    settings: Record<string, string | null>;
    assets: Record<string, { slot: string; path: string; version: number }>;
    slots: string[];
}>();

const s = (key: string, fallback = ''): string => props.settings[key] ?? fallback;

const form = useForm({
    'branding.site_name': s('branding.site_name', 'Mulkihawler'),
    'branding.tagline_ckb': s('branding.tagline_ckb'),
    'branding.tagline_ar': s('branding.tagline_ar'),
    'branding.tagline_en': s('branding.tagline_en'),
    'branding.color_brand': s('branding.color_brand', '15 62 89'),
    'branding.color_accent': s('branding.color_accent', '201 162 39'),
    'branding.color_surface': s('branding.color_surface', '250 250 249'),
    'branding.color_ink': s('branding.color_ink', '23 23 23'),
    'branding.dark_mode_enabled': s('branding.dark_mode_enabled') === '1',
    'branding.pwa_name': s('branding.pwa_name'),
    'branding.pwa_short_name': s('branding.pwa_short_name'),
});

const colourFields = [
    'branding.color_brand',
    'branding.color_accent',
    'branding.color_surface',
    'branding.color_ink',
] as const;
</script>

<template>
    <Head :title="t('nav.branding')" />

    <AdminLayout>
        <template #title>{{ t('nav.branding') }}</template>

        <form class="space-y-6" @submit.prevent="form.put('/admin/branding')">
            <AppCard :title="t('admin.branding.identity')" :description="t('admin.branding.identity_hint')">
                <div class="space-y-5">
                    <AppInput
                        v-model="form['branding.site_name']"
                        :label="t('admin.branding.site_name')"
                        :error="form.errors['branding.site_name']"
                        required
                    />
                    <AppInput v-model="form['branding.tagline_ckb']" :label="t('admin.branding.tagline_ckb')" />
                    <AppInput v-model="form['branding.tagline_ar']" :label="t('admin.branding.tagline_ar')" />
                    <AppInput v-model="form['branding.tagline_en']" :label="t('admin.branding.tagline_en')" dir="ltr" />
                </div>
            </AppCard>

            <AppCard :title="t('admin.branding.palette')" :description="t('admin.branding.palette_hint')">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div v-for="field in colourFields" :key="field" class="flex items-end gap-3">
                        <div class="min-w-0 flex-1">
                            <AppInput
                                v-model="form[field]"
                                :label="t(`admin.branding.${field.split('.').pop()}`)"
                                :error="form.errors[field]"
                                :hint="t('admin.branding.rgb_hint')"
                                dir="ltr"
                            />
                        </div>
                        <!--
                          Colours are stored as space-separated RGB triples, not
                          hex, because the stylesheet consumes them through
                          rgb(var(--token) / <alpha-value>). Hex would make every
                          translucent surface impossible without a conversion at
                          runtime.
                        -->
                        <span
                            class="mb-1 h-10 w-10 shrink-0 rounded-card border border-line"
                            :style="{ backgroundColor: `rgb(${form[field]})` }"
                            aria-hidden="true"
                        />
                    </div>
                </div>
            </AppCard>

            <AppCard :title="t('admin.branding.pwa')" :description="t('admin.branding.pwa_hint')">
                <div class="space-y-5">
                    <AppInput v-model="form['branding.pwa_name']" :label="t('admin.branding.pwa_name')" />
                    <AppInput v-model="form['branding.pwa_short_name']" :label="t('admin.branding.pwa_short_name')" />
                    <AppToggle
                        v-model="form['branding.dark_mode_enabled']"
                        :label="t('admin.branding.dark_mode')"
                        :description="t('admin.branding.dark_mode_hint')"
                    />
                </div>
            </AppCard>

            <AppCard :title="t('admin.branding.assets')" :description="t('admin.branding.assets_hint')">
                <ul class="divide-y divide-line">
                    <li v-for="slot in slots" :key="slot" class="flex items-center justify-between gap-4 py-3">
                        <span class="truncate text-sm text-ink">{{ slot }}</span>
                        <span v-if="assets[slot]" class="numeral text-xs text-ink-muted">
                            v{{ assets[slot].version }}
                        </span>
                        <span v-else class="text-xs text-ink-faint">{{ t('app.states.empty') }}</span>
                    </li>
                </ul>
            </AppCard>

            <div class="flex justify-end gap-3">
                <AppButton type="submit" :loading="form.processing">
                    {{ t('app.actions.save') }}
                </AppButton>
            </div>
        </form>
    </AdminLayout>
</template>
