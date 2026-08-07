<script setup lang="ts">
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t, formatNumber } from '@/lib/i18n';

/*
 * Place category administration (File two §5.1).
 *
 * The requirement is that an operator can add a place type and change its icon
 * and name WITHOUT a code change. The table supported that from the start; the
 * screen to do it did not exist, so the only way to add a category was to edit
 * the seeder and redeploy.
 *
 * System categories show their key as read-only text rather than a disabled
 * input. A greyed-out box invites the operator to try, fail, and wonder why —
 * the key is immutable because stored distances reference it, and saying that
 * plainly is better than a field that quietly refuses.
 */
interface Category {
    id: number;
    key: string;
    group: string;
    name_ckb: string;
    name_ar: string | null;
    name_en: string | null;
    icon: string | null;
    colour: string | null;
    default_radius_m: number;
    amenity_weight: string;
    is_system: boolean;
    is_active: boolean;
    sort_order: number;
    places_count: number;
}

defineProps<{
    categories: Category[];
    groups: string[];
    can: { manage: boolean };
}>();

const editing = ref<number | null>(null);
const creating = ref(false);

const form = useForm({
    key: '',
    group: 'other',
    name_ckb: '',
    name_ar: '',
    name_en: '',
    icon: '',
    colour: '',
    default_radius_m: 3000,
    amenity_weight: '0.50',
    sort_order: 0,
    is_active: true,
});

function startEdit(category: Category): void {
    editing.value = category.id;
    creating.value = false;
    form.defaults({
        key: category.key,
        group: category.group,
        name_ckb: category.name_ckb,
        name_ar: category.name_ar ?? '',
        name_en: category.name_en ?? '',
        icon: category.icon ?? '',
        colour: category.colour ?? '',
        default_radius_m: category.default_radius_m,
        amenity_weight: category.amenity_weight,
        sort_order: category.sort_order,
        is_active: category.is_active,
    });
    form.reset();
}

function startCreate(): void {
    creating.value = true;
    editing.value = null;
    form.defaults({
        key: '', group: 'other', name_ckb: '', name_ar: '', name_en: '',
        icon: '', colour: '', default_radius_m: 3000, amenity_weight: '0.50',
        sort_order: 0, is_active: true,
    });
    form.reset();
}

function cancel(): void {
    editing.value = null;
    creating.value = false;
    form.clearErrors();
}

function save(): void {
    if (creating.value) {
        form.post('/admin/places/categories', {
            preserveScroll: true,
            onSuccess: () => cancel(),
        });

        return;
    }

    if (editing.value !== null) {
        form.put(`/admin/places/categories/${editing.value}`, {
            preserveScroll: true,
            onSuccess: () => cancel(),
        });
    }
}

function deactivate(category: Category): void {
    router.post(`/admin/places/categories/${category.id}/deactivate`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="t('geography.categories.title')" />

    <AdminLayout>
        <template #title>{{ t('geography.categories.title') }}</template>

        <div v-if="can.manage" class="mb-5">
            <AppButton variant="primary" @click="startCreate">
                {{ t('app.actions.create') }}
            </AppButton>
        </div>

        <AppCard v-if="creating || editing !== null" class="mb-6">
            <div class="grid gap-4 sm:grid-cols-2">
                <div v-if="creating">
                    <label for="key" class="mh-label mb-1 block">key</label>
                    <input
                        id="key" v-model="form.key" type="text" dir="ltr"
                        class="w-full rounded-card border border-line bg-surface-raised px-3 py-2 text-sm text-ink
                               focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                    >
                    <p v-if="form.errors.key" class="mt-1 text-xs text-negative">{{ form.errors.key }}</p>
                </div>

                <div>
                    <label for="group" class="mh-label mb-1 block">group</label>
                    <select
                        id="group" v-model="form.group"
                        class="w-full rounded-card border border-line bg-surface-raised px-3 py-2 text-sm text-ink
                               focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                    >
                        <option v-for="g in groups" :key="g" :value="g">{{ g }}</option>
                    </select>
                </div>

                <div>
                    <label for="name_ckb" class="mh-label mb-1 block">ckb</label>
                    <input
                        id="name_ckb" v-model="form.name_ckb" type="text"
                        class="w-full rounded-card border border-line bg-surface-raised px-3 py-2 text-sm text-ink
                               focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                    >
                    <p v-if="form.errors.name_ckb" class="mt-1 text-xs text-negative">{{ form.errors.name_ckb }}</p>
                </div>

                <div>
                    <label for="name_ar" class="mh-label mb-1 block">ar</label>
                    <input
                        id="name_ar" v-model="form.name_ar" type="text"
                        class="w-full rounded-card border border-line bg-surface-raised px-3 py-2 text-sm text-ink
                               focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                    >
                </div>

                <div>
                    <label for="name_en" class="mh-label mb-1 block">en</label>
                    <input
                        id="name_en" v-model="form.name_en" type="text" dir="ltr"
                        class="w-full rounded-card border border-line bg-surface-raised px-3 py-2 text-sm text-ink
                               focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                    >
                </div>

                <div>
                    <label for="icon" class="mh-label mb-1 block">icon</label>
                    <input
                        id="icon" v-model="form.icon" type="text" dir="ltr"
                        class="w-full rounded-card border border-line bg-surface-raised px-3 py-2 text-sm text-ink
                               focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                    >
                </div>

                <div>
                    <label for="radius" class="mh-label mb-1 block">default_radius_m</label>
                    <input
                        id="radius" v-model.number="form.default_radius_m" type="number" dir="ltr"
                        class="w-full rounded-card border border-line bg-surface-raised px-3 py-2 text-sm text-ink
                               focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                    >
                    <p v-if="form.errors.default_radius_m" class="mt-1 text-xs text-negative">
                        {{ form.errors.default_radius_m }}
                    </p>
                </div>

                <div>
                    <label for="weight" class="mh-label mb-1 block">amenity_weight</label>
                    <input
                        id="weight" v-model="form.amenity_weight" type="text" dir="ltr"
                        class="w-full rounded-card border border-line bg-surface-raised px-3 py-2 text-sm text-ink
                               focus:border-brand focus:outline-none focus:ring-2 focus:ring-accent"
                    >
                </div>
            </div>

            <div class="mt-5 flex gap-3">
                <AppButton variant="primary" :disabled="form.processing" @click="save">
                    {{ t('app.actions.save') }}
                </AppButton>
                <AppButton variant="ghost" @click="cancel">
                    {{ t('app.actions.cancel') }}
                </AppButton>
            </div>
        </AppCard>

        <ul class="space-y-2">
            <li
                v-for="category in categories"
                :key="category.id"
                class="flex flex-wrap items-center gap-3 rounded-card border border-line bg-surface-raised p-4"
                :class="category.is_active ? '' : 'opacity-50'"
            >
                <span
                    class="inline-block h-3 w-3 shrink-0 rounded-full"
                    :style="{ backgroundColor: category.colour ?? 'currentColor' }"
                    aria-hidden="true"
                />

                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-ink">{{ category.name_ckb }}</p>
                    <p class="numeral text-xs text-ink-muted" dir="ltr">
                        {{ category.key }} · {{ category.group }} ·
                        {{ formatNumber(category.default_radius_m) }} m
                    </p>
                </div>

                <span v-if="category.is_system" class="rounded-card bg-surface-sunken px-2 py-0.5 text-xs text-ink-muted">
                    {{ t('geography.categories.system') }}
                </span>

                <span class="numeral text-xs text-ink-faint">
                    {{ t('geography.categories.places_count') }}: {{ formatNumber(category.places_count) }}
                </span>

                <div v-if="can.manage" class="flex gap-2">
                    <AppButton variant="ghost" size="sm" @click="startEdit(category)">
                        {{ t('app.actions.edit') }}
                    </AppButton>
                    <AppButton
                        v-if="category.is_active"
                        variant="ghost" size="sm"
                        @click="deactivate(category)"
                    >
                        {{ t('app.actions.disable') }}
                    </AppButton>
                </div>
            </li>
        </ul>

        <AppAlert v-if="editing !== null" variant="info" class="mt-5">
            {{ t('geography.categories.key_locked') }}
        </AppAlert>
    </AdminLayout>
</template>
