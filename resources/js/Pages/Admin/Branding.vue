<script setup lang="ts">
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import AppSelect from '@/Components/ui/AppSelect.vue';
import AppToggle from '@/Components/ui/AppToggle.vue';
import { t } from '@/lib/i18n';

const props = defineProps<{
    settings: Record<string, string | null>;
    typography: Record<string, string | null>;
    assets: Record<string, { slot: string; path: string; version: number }>;
    slots: string[];
}>();

const s = (key: string, fallback = ''): string => props.settings[key] ?? fallback;
const ty = (key: string, fallback: string): string => props.typography[key] ?? fallback;

/*
 * Public typography (Wave 2B §7): the owner's font and size controls, on the
 * same settings store and permission as the rest of branding. Defaults
 * mirror the Blade token block exactly, and the reset button simply returns
 * the form to those defaults — saving then persists them like any value.
 */
const TYPOGRAPHY_DEFAULTS = {
    'typography.font_latin': 'outfit',
    'typography.font_ckb': 'k24',
    'typography.font_ar': 'noto_sans_arabic',
    'typography.public_scale': '100',
} as const;

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
    'typography.font_latin': ty('typography.font_latin', TYPOGRAPHY_DEFAULTS['typography.font_latin']),
    'typography.font_ckb': ty('typography.font_ckb', TYPOGRAPHY_DEFAULTS['typography.font_ckb']),
    'typography.font_ar': ty('typography.font_ar', TYPOGRAPHY_DEFAULTS['typography.font_ar']),
    'typography.public_scale': ty('typography.public_scale', TYPOGRAPHY_DEFAULTS['typography.public_scale']),
});

const colourFields = [
    'branding.color_brand',
    'branding.color_accent',
    'branding.color_surface',
    'branding.color_ink',
] as const;

const latinOptions = computed(() => [
    { value: 'outfit', label: t('admin.typography.font_outfit') },
    { value: 'noto_sans', label: t('admin.typography.font_noto_sans') },
    { value: 'system', label: t('admin.typography.font_system') },
]);

const ckbOptions = computed(() => [
    { value: 'k24', label: t('admin.typography.font_k24') },
    { value: 'kufi', label: t('admin.typography.font_kufi') },
]);

const arOptions = computed(() => [
    { value: 'noto_sans_arabic', label: t('admin.typography.font_noto_sans_arabic') },
    { value: 'kufi', label: t('admin.typography.font_kufi') },
]);

const scaleOptions = computed(() =>
    ['90', '95', '100', '105', '110', '115', '120'].map((value) => ({
        value,
        label: value === '100' ? `${value}% — ${t('admin.typography.scale_default')}` : `${value}%`,
    })));

/* The same stacks the Blade token block resolves — preview only. */
const previewStacks: Record<string, string> = {
    outfit: "'Outfit', 'Noto Sans', ui-sans-serif, system-ui, sans-serif",
    noto_sans: "'Noto Sans', ui-sans-serif, system-ui, sans-serif",
    system: 'ui-sans-serif, system-ui, sans-serif',
    k24: "'K24', 'Noto Kufi Arabic', ui-sans-serif, sans-serif",
    kufi: "'Noto Kufi Arabic', ui-sans-serif, sans-serif",
    noto_sans_arabic: "'Noto Sans Arabic', 'Noto Kufi Arabic', ui-sans-serif, sans-serif",
};

const previewScale = computed(() => Number(form['typography.public_scale']) / 100);

function resetTypography(): void {
    form['typography.font_latin'] = TYPOGRAPHY_DEFAULTS['typography.font_latin'];
    form['typography.font_ckb'] = TYPOGRAPHY_DEFAULTS['typography.font_ckb'];
    form['typography.font_ar'] = TYPOGRAPHY_DEFAULTS['typography.font_ar'];
    form['typography.public_scale'] = TYPOGRAPHY_DEFAULTS['typography.public_scale'];
}
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

            <AppCard :title="t('admin.typography.title')" :description="t('admin.typography.hint')">
                <div class="space-y-5">
                    <div class="grid gap-5 sm:grid-cols-3">
                        <AppSelect
                            v-model="form['typography.font_ckb']"
                            :label="t('admin.typography.font_ckb')"
                            :options="ckbOptions"
                            :error="form.errors['typography.font_ckb']"
                        />
                        <AppSelect
                            v-model="form['typography.font_ar']"
                            :label="t('admin.typography.font_ar')"
                            :options="arOptions"
                            :error="form.errors['typography.font_ar']"
                        />
                        <AppSelect
                            v-model="form['typography.font_latin']"
                            :label="t('admin.typography.font_latin')"
                            :options="latinOptions"
                            :error="form.errors['typography.font_latin']"
                        />
                    </div>

                    <AppSelect
                        v-model="form['typography.public_scale']"
                        :label="t('admin.typography.scale')"
                        :options="scaleOptions"
                        :error="form.errors['typography.public_scale']"
                    />
                    <p class="text-xs text-ink-faint">{{ t('admin.typography.scale_hint') }}</p>

                    <!-- Live sample: the exact stacks the public shell will
                         resolve, at the chosen scale. One line per script,
                         shown together whatever the admin's own locale — so
                         these are fixed FONT SPECIMENS in each language's own
                         script (the product's real tagline), not UI copy
                         routed through t(): a translation call could only
                         yield the current locale's line. -->
                    <div class="rounded-card border border-line bg-surface-sunken p-4" :style="{ fontSize: `${previewScale}em` }">
                        <p class="mh-label mb-2">{{ t('admin.typography.preview') }}</p>
                        <p lang="ckb" dir="rtl" :style="{ fontFamily: previewStacks[form['typography.font_ckb']] }">
                            داتای پشتڕاستکراو، بە بەڵگەوە
                        </p>
                        <p class="mt-1.5" lang="ar" dir="rtl" :style="{ fontFamily: previewStacks[form['typography.font_ar']] }">
                            بيانات موثّقة، مع أدلّتها
                        </p>
                        <p class="mt-1.5" lang="en" dir="ltr" :style="{ fontFamily: previewStacks[form['typography.font_latin']] }">
                            Verified data, with its evidence
                        </p>
                    </div>

                    <div>
                        <AppButton variant="ghost" size="sm" @click="resetTypography">
                            {{ t('admin.typography.reset') }}
                        </AppButton>
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
