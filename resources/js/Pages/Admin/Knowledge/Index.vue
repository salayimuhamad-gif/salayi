<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import AppSelect from '@/Components/ui/AppSelect.vue';
import AppToggle from '@/Components/ui/AppToggle.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import { t, formatNumber } from '@/lib/i18n';

interface Option { value: string | number; label: string; ai_usable?: boolean }

interface Event_ {
    id: number; title: string; event_type: string; direction: string; strength: number;
    effective_date: string | null; expires_on: string | null; status: string;
    ai_usage_permitted: boolean; ai_usable: boolean; has_source: boolean;
    allowed_transitions: string[];
}

const props = defineProps<{
    events: { data: Event_[] };
    filters: { q: string; status: string; event_type: string };
    options: {
        event_types: Option[]; statuses: Option[]; directions: Option[];
        evidence_classes: Option[]; entity_types: Option[];
        projects: Option[]; areas: Option[];
    };
    counts: { review: number; ai_usable: number };
    can: { create: boolean; approve: boolean };
}>();

const filters = ref({ ...props.filters });
const creating = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.get('/admin/knowledge', filters.value, { preserveState: true, replace: true });
    }, 300);
}, { deep: true });

const form = useForm({
    title_ckb: '', summary_ckb: '',
    event_type: 'construction', direction: 'neutral', strength: 3,
    entity_type: 'project', entity_id: null as number | null,
    effective_date: '', expected_date: '', expires_on: '',
    source: '', source_url: '', confidence: 'medium',
    // Phase 12: every NEW event states what kind of claim it makes; the
    // observation default matches how unclassified legacy rows are read.
    evidence_class: 'admin_observation',
    ai_usage_permitted: false,
});

// Phase 12: an event about "prices in Ankawa" points at an area, not a
// project. The options list follows the chosen entity type, and a stale id
// from the other list is cleared rather than silently submitted.
const entityOptions = computed(() => (form.entity_type === 'area' ? props.options.areas : props.options.projects));

watch(() => form.entity_type, () => { form.entity_id = null; });

// Spec 17.5 — an event the AI may use is one it will cite, so it needs
// something to cite. Shown while the toggle is being set, not afterwards.
const aiNeedsSource = computed(() => form.ai_usage_permitted && form.source.trim() === '');

// Phase 12 — a verified FACT is spoken with attribution; without a source it
// belongs in another class. Same live treatment as the AI-source rule.
const factNeedsSource = computed(() => form.evidence_class === 'verified_fact' && form.source.trim() === '');

function create(): void {
    form.post('/admin/knowledge', {
        preserveScroll: true,
        onSuccess: () => { form.reset(); creating.value = false; },
    });
}

function move(event: Event_, status: string): void {
    router.post(`/admin/knowledge/${event.id}/transition`, { status }, { preserveScroll: true });
}

const tone = (direction: string): string => ({
    positive: 'text-positive', negative: 'text-negative',
}[direction] ?? 'text-ink-muted');
</script>

<template>
    <Head :title="t('nav.knowledge')" />

    <AdminLayout>
        <template #title>{{ t('nav.knowledge') }}</template>

        <div class="mb-6 grid gap-5 sm:grid-cols-2">
            <AppCard :title="t('knowledge.awaiting_review')">
                <p
                    class="numeral font-display text-2xl font-semibold"
                    :class="counts.review > 0 ? 'text-caution' : 'text-ink-faint'"
                >
                    {{ formatNumber(counts.review) }}
                </p>
            </AppCard>

            <AppCard :title="t('knowledge.ai_usable_count')">
                <p class="numeral font-display text-2xl font-semibold text-brand">
                    {{ formatNumber(counts.ai_usable) }}
                </p>
                <p class="mt-1 text-xs text-ink-muted">{{ t('knowledge.ai_gate_hint') }}</p>
            </AppCard>
        </div>

        <div class="mb-6 flex flex-wrap items-end gap-3">
            <div class="min-w-0 flex-1">
                <label for="q" class="mh-label mb-1 block">{{ t('app.actions.search') }}</label>
                <input
                    id="q" v-model="filters.q" type="search"
                    class="w-full rounded-card border border-line bg-surface-raised px-3 py-2 text-ink
                           focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                >
            </div>

            <div>
                <label for="s" class="mh-label mb-1 block">{{ t('projects.publication.status') }}</label>
                <select
                    id="s" v-model="filters.status"
                    class="rounded-card border border-line bg-surface-raised px-3 py-2 text-ink
                           focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                >
                    <option value="">{{ t('app.actions.filter') }}</option>
                    <option v-for="s in options.statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                </select>
            </div>

            <AppButton v-if="can.create && !creating" @click="creating = true">
                {{ t('knowledge.create') }}
            </AppButton>
        </div>

        <AppCard v-if="creating" :title="t('knowledge.create')" class="mb-6">
            <form class="space-y-5" @submit.prevent="create">
                <AppInput v-model="form.title_ckb" :label="t('projects.fields.name_ckb')" :error="form.errors.title_ckb" required />
                <AppInput v-model="form.summary_ckb" :label="t('knowledge.summary')" :error="form.errors.summary_ckb" />

                <div class="grid gap-5 sm:grid-cols-3">
                    <AppSelect
                        v-model="form.event_type" :label="t('knowledge.event_type')"
                        :options="options.event_types" :error="form.errors.event_type" required
                    />
                    <AppSelect
                        v-model="form.direction" :label="t('knowledge.direction_label')"
                        :options="options.directions" :error="form.errors.direction" required
                    />
                    <AppInput
                        v-model="form.strength" :label="t('knowledge.strength')"
                        :hint="t('knowledge.strength_hint')" :error="form.errors.strength" dir="ltr" required
                    />
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <AppSelect
                        v-model="form.entity_type" :label="t('knowledge.entity_type')"
                        :options="options.entity_types" :error="form.errors.entity_type" required
                    />
                    <AppSelect
                        v-model="form.entity_id"
                        :label="form.entity_type === 'area' ? t('nav.geography.areas') : t('nav.projects')"
                        :options="entityOptions" :placeholder="t('app.states.empty')"
                        :error="form.errors.entity_id"
                    />
                </div>

                <div>
                    <AppSelect
                        v-model="form.evidence_class" :label="t('knowledge.evidence_class')"
                        :options="options.evidence_classes" :error="form.errors.evidence_class" required
                    />
                    <p class="mt-1 text-xs text-ink-muted">{{ t('knowledge.evidence_class_hint') }}</p>
                </div>

                <div class="grid gap-5 sm:grid-cols-3">
                    <AppInput
                        v-model="form.effective_date" type="date" :label="t('knowledge.effective_date')"
                        :error="form.errors.effective_date" required
                    />
                    <AppInput
                        v-model="form.expected_date" type="date" :label="t('knowledge.expected_date')"
                        :hint="t('knowledge.expected_hint')" :error="form.errors.expected_date"
                    />
                    <AppInput
                        v-model="form.expires_on" type="date" :label="t('knowledge.expires_on')"
                        :hint="t('knowledge.expires_hint')" :error="form.errors.expires_on"
                    />
                </div>

                <AppInput v-model="form.source" :label="t('projects.fields.source')" :error="form.errors.source" />
                <AppInput v-model="form.source_url" :label="t('geography.fields.source_url')" :error="form.errors.source_url" dir="ltr" />

                <AppToggle
                    v-model="form.ai_usage_permitted"
                    :label="t('knowledge.ai_permission')"
                    :description="t('knowledge.ai_permission_hint')"
                />

                <AppAlert v-if="aiNeedsSource" variant="warning">
                    {{ t('knowledge.errors.ai_needs_source') }}
                </AppAlert>

                <AppAlert v-if="factNeedsSource" variant="warning">
                    {{ t('knowledge.errors.fact_needs_source') }}
                </AppAlert>

                <div class="flex justify-end gap-2">
                    <AppButton variant="ghost" size="sm" @click="creating = false">{{ t('app.actions.cancel') }}</AppButton>
                    <AppButton type="submit" size="sm" :disabled="aiNeedsSource || factNeedsSource" :loading="form.processing">
                        {{ t('app.actions.save') }}
                    </AppButton>
                </div>
            </form>
        </AppCard>

        <AppEmptyState v-if="events.data.length === 0" :title="t('knowledge.empty')" :description="t('knowledge.empty_hint')" />

        <div v-else class="mh-card overflow-hidden">
            <ul class="divide-y divide-line">
                <li v-for="event in events.data" :key="event.id" class="px-5 py-4">
                    <div class="flex flex-wrap items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium text-ink">{{ event.title }}</p>
                            <p class="mt-0.5 text-xs" :class="tone(event.direction)">
                                {{ t(`knowledge.event_types.${event.event_type}`) }}
                                · {{ t(`knowledge.direction.${event.direction}`) }}
                                <span class="numeral">({{ event.strength }}/5)</span>
                            </p>
                        </div>

                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            <!-- The three conditions, shown as one answer. An
                                 editor who set the permission and sees this off
                                 needs to know it is the status or the expiry. -->
                            <span
                                v-if="event.ai_usage_permitted"
                                class="rounded-full px-2 py-0.5 text-xs"
                                :class="event.ai_usable ? 'bg-brand/10 text-brand' : 'bg-surface-sunken text-ink-faint'"
                            >
                                {{ event.ai_usable ? t('knowledge.ai_active') : t('knowledge.ai_blocked') }}
                            </span>

                            <span
                                v-if="!event.has_source"
                                class="rounded-full bg-caution/10 px-2 py-0.5 text-xs text-caution"
                            >
                                {{ t('app.meta.no_source') }}
                            </span>

                            <span class="rounded-full bg-surface-sunken px-2.5 py-0.5 text-xs text-ink-muted">
                                {{ t(`knowledge.statuses.${event.status}`) }}
                            </span>
                        </div>
                    </div>

                    <div v-if="event.allowed_transitions.length > 0" class="mt-3 flex flex-wrap gap-2">
                        <AppButton
                            v-for="next in event.allowed_transitions" :key="next"
                            variant="secondary" size="sm"
                            :disabled="(next === 'approved' || next === 'published') && !can.approve"
                            @click="move(event, next)"
                        >
                            {{ t(`knowledge.statuses.${next}`) }}
                        </AppButton>
                    </div>
                </li>
            </ul>
        </div>
    </AdminLayout>
</template>
