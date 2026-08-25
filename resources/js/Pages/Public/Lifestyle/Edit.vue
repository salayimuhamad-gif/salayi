<script setup lang="ts">
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t } from '@/lib/i18n';
import { useLocale } from '@/Composables/useLocale';

/*
 * Bare public paths are the *default-locale* (Sorani) URLs under
 * `prefix_except_default`. Emitting them from an Arabic or English page threw
 * the visitor back into Sorani, so each one is wrapped by `localized()`.
 */
const { localized } = useLocale();

/*
 * Lifestyle profile form (File two §8).
 *
 * The household states where its life happens; the platform ranks projects
 * against that rather than against price and district alone.
 *
 * Sensitive coordinates are handled carefully in both directions. The server
 * never sends back the latitude of a workplace or a school — only whether a pin
 * is set — so an existing pin shows as "set" and is left untouched unless the
 * household deliberately replaces it. Re-submitting the form must not silently
 * blank a location the page was never allowed to see.
 */
interface PriorityKind {
    value: string;
    label: string;
    component: string;
    specific_location: boolean;
    sensitive: boolean;
}

interface PriorityRow {
    kind: string;
    label: string;
    latitude: number | null;
    longitude: number | null;
    place_id: number | null;
    importance: number;
    max_distance_m: number | null;
    is_required: boolean;
    has_location?: boolean;
}

const props = defineProps<{
    profile: {
        id: number;
        label: string | null;
        budget_min: string | null;
        budget_max: string | null;
        budget_currency: string;
        property_types: string[];
        household_adults: number | null;
        household_children: number | null;
        priorities: PriorityRow[];
    } | null;
    priority_kinds: PriorityKind[];
}>();

const form = useForm({
    label: props.profile?.label ?? '',
    budget_min: props.profile?.budget_min ?? '',
    budget_max: props.profile?.budget_max ?? '',
    budget_currency: props.profile?.budget_currency ?? 'USD',
    property_types: props.profile?.property_types ?? [],
    household_adults: props.profile?.household_adults ?? null,
    household_children: props.profile?.household_children ?? null,
    priorities: (props.profile?.priorities ?? []) as PriorityRow[],
});

const propertyTypes = ['apartment', 'house', 'villa', 'land', 'commercial', 'office'];
const newKind = ref<string>('workplace');

function addPriority(): void {
    form.priorities.push({
        kind: newKind.value,
        label: '',
        latitude: null,
        longitude: null,
        place_id: null,
        importance: 3,
        max_distance_m: null,
        is_required: false,
    });
}

function removePriority(index: number): void {
    form.priorities.splice(index, 1);
}

function kindOf(value: string): PriorityKind | undefined {
    return props.priority_kinds.find((k) => k.value === value);
}

function submit(): void {
    form.post(localized('/lifestyle'), { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('advisor.lifestyle.title')" />

    <PublicLayout>
        <article class="mx-auto max-w-3xl space-y-6">
            <h1 class="font-display text-2xl font-bold text-ink">
                {{ t('advisor.lifestyle.title') }}
            </h1>

            <AppCard :title="t('advisor.lifestyle.budget')">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="bmin" class="mh-label mb-1 block">min</label>
                        <input
                            id="bmin" v-model="form.budget_min" type="text" dir="ltr"
                            class="mh-field-glass min-h-11 w-full rounded-card px-3 py-2 text-sm"
                        >
                    </div>
                    <div>
                        <label for="bmax" class="mh-label mb-1 block">max</label>
                        <input
                            id="bmax" v-model="form.budget_max" type="text" dir="ltr"
                            class="mh-field-glass min-h-11 w-full rounded-card px-3 py-2 text-sm"
                        >
                        <p v-if="form.errors.budget_max" class="mt-1 text-xs text-negative">
                            {{ form.errors.budget_max }}
                        </p>
                    </div>
                    <div>
                        <label for="bcur" class="mh-label mb-1 block">currency</label>
                        <select
                            id="bcur" v-model="form.budget_currency"
                            class="mh-field-glass min-h-11 w-full rounded-card px-3 py-2 text-sm"
                        >
                            <option value="USD">USD</option>
                            <option value="IQD">IQD</option>
                        </select>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <label
                        v-for="type in propertyTypes"
                        :key="type"
                        class="flex items-center gap-2 rounded-card border border-line px-3 py-1.5 text-xs"
                    >
                        <input
                            v-model="form.property_types"
                            type="checkbox"
                            :value="type"
                            class="h-4 w-4 rounded border-line text-brand focus:ring-2 focus:ring-accent"
                        >
                        <span>{{ t(`market.property_types.${type}`) }}</span>
                    </label>
                </div>
            </AppCard>

            <AppCard :title="t('advisor.lifestyle.add_priority')">
                <div class="mb-4 flex flex-wrap items-end gap-3">
                    <div class="min-w-0 flex-1">
                        <label for="kind" class="mh-label mb-1 block">kind</label>
                        <select
                            id="kind" v-model="newKind"
                            class="mh-field-glass min-h-11 w-full rounded-card px-3 py-2 text-sm"
                        >
                            <option v-for="kind in priority_kinds" :key="kind.value" :value="kind.value">
                                {{ kind.label }}
                            </option>
                        </select>
                    </div>
                    <AppButton variant="ghost" @click="addPriority">
                        {{ t('app.actions.add') }}
                    </AppButton>
                </div>

                <ul class="space-y-3">
                    <li
                        v-for="(priority, index) in form.priorities"
                        :key="index"
                        class="rounded-card border border-line p-3"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="text-sm font-medium text-ink">
                                {{ kindOf(priority.kind)?.label ?? priority.kind }}
                            </p>
                            <AppButton variant="ghost" size="sm" @click="removePriority(index)">
                                {{ t('app.actions.delete') }}
                            </AppButton>
                        </div>

                        <div class="mt-3 grid gap-3 sm:grid-cols-3">
                            <div>
                                <label class="mh-label mb-1 block">{{ t('advisor.lifestyle.importance') }}</label>
                                <input
                                    v-model.number="priority.importance" type="number" min="1" max="5" dir="ltr"
                                    class="mh-field-glass min-h-11 w-full rounded-card px-3 py-2 text-sm"
                                >
                            </div>
                            <div>
                                <label class="mh-label mb-1 block">{{ t('advisor.lifestyle.max_distance') }}</label>
                                <input
                                    v-model.number="priority.max_distance_m" type="number" dir="ltr"
                                    class="mh-field-glass min-h-11 w-full rounded-card px-3 py-2 text-sm"
                                >
                            </div>
                            <label class="flex items-center gap-2 self-end text-sm text-ink">
                                <input
                                    v-model="priority.is_required" type="checkbox"
                                    class="h-4 w-4 rounded border-line text-brand focus:ring-2 focus:ring-accent"
                                >
                                <span>{{ t('advisor.lifestyle.required') }}</span>
                            </label>
                        </div>

                        <!-- An existing sensitive pin is reported as set, never
                             echoed back. Leaving it untouched preserves it. -->
                        <p
                            v-if="priority.has_location && kindOf(priority.kind)?.sensitive"
                            class="mt-2 text-xs text-ink-faint"
                        >
                            {{ t('geography.nearby.show_on_map') }}
                        </p>
                    </li>
                </ul>
            </AppCard>

            <AppAlert v-if="form.hasErrors" variant="danger">
                {{ t('app.states.error') }}
            </AppAlert>

            <AppButton variant="primary" :disabled="form.processing" @click="submit">
                {{ t('app.actions.save') }}
            </AppButton>
        </article>
    </PublicLayout>
</template>
