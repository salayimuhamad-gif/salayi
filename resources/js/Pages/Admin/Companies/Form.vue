<script setup lang="ts">
import { computed } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppCard from '@/Components/ui/AppCard.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppInput from '@/Components/ui/AppInput.vue';
import AppSelect from '@/Components/ui/AppSelect.vue';
import AppToggle from '@/Components/ui/AppToggle.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t } from '@/lib/i18n';

interface RoleOption {
    value: string;
    label: string;
    asserts_official: boolean;
    requires_disclosure: boolean;
    priority: number;
}

interface Association {
    id: number;
    project: string | null;
    role: string;
    asserts_official: boolean;
    is_sponsored: boolean;
    disclosure_label: string | null;
    display_priority: number;
}

const props = defineProps<{
    company: Record<string, unknown> | null;
    options: { roles: RoleOption[]; projects: Array<{ value: number; label: string }> };
}>();

const isEdit = computed(() => props.company !== null);
const companyId = computed(() => Number(props.company?.id ?? 0));
const str = (key: string, fallback = ''): string => String(props.company?.[key] ?? fallback);

const form = useForm({
    legal_name: str('legal_name'),
    brand_name: str('brand_name'),
    slug: str('slug'),
    name_ckb: str('name_ckb'),
    name_ar: str('name_ar'),
    name_en: str('name_en'),
    description_ckb: str('description_ckb'),
    website: str('website'),
    email: str('email'),
    telegram_username: str('telegram_username'),
    license_number: str('license_number'),
    license_authority: str('license_authority'),
    license_expires_at: str('license_expires_at'),
});

const verification = useForm({
    verification_status: str('verification_status', 'pending'),
    notes: str('verification_notes'),
});

const association = useForm({
    project_id: null as number | null,
    role: 'independent_brokerage',
    is_sponsored: false,
    disclosure_label: '',
    starts_on: '',
    ends_on: '',
});

const associations = computed(() => (props.company?.associations ?? []) as Association[]);
const isVerified = computed(() => str('verification_status') === 'verified');

const selectedRole = computed(
    () => props.options.roles.find((r) => r.value === association.role),
);

// An official-status claim needs the company verified first. Shown while the
// role is being chosen rather than as a rejection afterwards.
const officialBlocked = computed(
    () => (selectedRole.value?.asserts_official ?? false) && !isVerified.value,
);

const disclosureRequired = computed(
    () => association.is_sponsored || (selectedRole.value?.requires_disclosure ?? false),
);

function save(): void {
    // An if/else rather than a ternary: the branches are side effects, not a
    // value, which is what @typescript-eslint/no-unused-expressions objected to.
    if (isEdit.value) {
        form.put(`/admin/companies/${companyId.value}`, { preserveScroll: true });
    } else {
        form.post('/admin/companies');
    }
}

function submitVerification(): void {
    verification.post(`/admin/companies/${companyId.value}/verify`, { preserveScroll: true });
}

function grant(): void {
    association.post(`/admin/companies/${companyId.value}/associations`, {
        preserveScroll: true,
        onSuccess: () => association.reset(),
    });
}
</script>

<template>
    <Head :title="isEdit ? form.name_ckb : t('companies.create')" />

    <AdminLayout>
        <template #title>{{ isEdit ? form.name_ckb : t('companies.create') }}</template>

        <form class="space-y-6" @submit.prevent="save">
            <AppCard :title="t('projects.wizard.identity')">
                <div class="space-y-5">
                    <AppInput
                        v-model="form.legal_name" :label="t('companies.fields.legal_name')"
                        :hint="t('companies.fields.legal_name_hint')" :error="form.errors.legal_name" required
                    />
                    <AppInput v-model="form.name_ckb" :label="t('projects.fields.name_ckb')" :error="form.errors.name_ckb" required />
                    <AppInput v-model="form.name_en" :label="t('projects.fields.name_en')" :error="form.errors.name_en" dir="ltr" />
                    <AppInput v-model="form.slug" :label="t('projects.fields.slug')" :error="form.errors.slug" dir="ltr" required />
                    <AppInput v-model="form.website" :label="t('projects.fields.official_url')" :error="form.errors.website" dir="ltr" />
                    <AppInput v-model="form.email" type="email" :label="t('identity.auth.email')" :error="form.errors.email" />
                    <AppInput v-model="form.telegram_username" :label="t('companies.fields.telegram')" :error="form.errors.telegram_username" dir="ltr" />
                </div>
            </AppCard>

            <AppCard :title="t('companies.fields.licence')" :description="t('companies.fields.licence_hint')">
                <div class="space-y-5">
                    <AppInput v-model="form.license_number" :label="t('companies.fields.licence_number')" :error="form.errors.license_number" dir="ltr" />
                    <AppInput v-model="form.license_authority" :label="t('companies.fields.licence_authority')" :error="form.errors.license_authority" />
                    <AppInput v-model="form.license_expires_at" type="date" :label="t('companies.fields.licence_expires')" :error="form.errors.license_expires_at" />
                </div>
            </AppCard>

            <div class="flex justify-end">
                <AppButton type="submit" :loading="form.processing">
                    {{ isEdit ? t('app.actions.save') : t('companies.create') }}
                </AppButton>
            </div>
        </form>

        <template v-if="isEdit">
            <AppCard :title="t('companies.verification.title')" :description="t('companies.verification.hint')" class="mt-6">
                <div class="space-y-5">
                    <AppSelect
                        v-model="verification.verification_status"
                        :label="t('companies.verification.status')"
                        :options="[
                            { value: 'pending', label: t('companies.verification.pending') },
                            { value: 'verified', label: t('companies.verification.verified') },
                            { value: 'rejected', label: t('companies.verification.rejected') },
                            { value: 'suspended', label: t('companies.verification.suspended') },
                        ]"
                    />
                    <AppInput v-model="verification.notes" :label="t('companies.verification.notes')" />
                    <div class="flex justify-end">
                        <AppButton size="sm" variant="secondary" :loading="verification.processing" @click="submitVerification">
                            {{ t('app.actions.save') }}
                        </AppButton>
                    </div>
                </div>
            </AppCard>

            <AppCard :title="t('companies.associations')" :description="t('companies.associations_hint')" class="mt-6">
                <ul v-if="associations.length > 0" class="mb-6 divide-y divide-line">
                    <li v-for="a in associations" :key="a.id" class="flex items-center gap-3 py-3">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm text-ink">{{ a.project }}</p>
                            <p class="mt-0.5 text-xs text-ink-muted">
                                {{ t(`companies.association_roles.${a.role}`) }}
                                <span v-if="a.disclosure_label" class="text-caution">· {{ a.disclosure_label }}</span>
                            </p>
                        </div>
                        <AppButton
                            variant="ghost" size="sm"
                            @click="router.delete(`/admin/companies/${companyId}/associations/${a.id}`, { preserveScroll: true })"
                        >
                            {{ t('app.actions.delete') }}
                        </AppButton>
                    </li>
                </ul>

                <div class="space-y-5 border-t border-line pt-5">
                    <AppSelect
                        v-model="association.project_id" :label="t('nav.projects')"
                        :options="options.projects" :placeholder="t('app.actions.filter')"
                        :error="association.errors.project_id"
                    />

                    <AppSelect
                        v-model="association.role" :label="t('companies.fields.role')"
                        :options="options.roles" :error="association.errors.role"
                    />

                    <!-- "Official developer" from an unverified company is
                         exactly the claim this product cannot make loosely. -->
                    <AppAlert v-if="officialBlocked" variant="warning">
                        {{ t('companies.errors.official_requires_verification') }}
                    </AppAlert>

                    <AppToggle
                        v-model="association.is_sponsored"
                        :label="t('companies.fields.is_sponsored')"
                        :description="t('companies.fields.is_sponsored_hint')"
                    />

                    <AppInput
                        v-if="disclosureRequired"
                        v-model="association.disclosure_label"
                        :label="t('companies.fields.disclosure_label')"
                        :hint="t('companies.fields.disclosure_hint')"
                        :error="association.errors.disclosure_label"
                        required
                    />

                    <div class="flex justify-end">
                        <AppButton
                            size="sm" :disabled="officialBlocked || !association.project_id"
                            :loading="association.processing" @click="grant"
                        >
                            {{ t('companies.grant_association') }}
                        </AppButton>
                    </div>
                </div>
            </AppCard>
        </template>
    </AdminLayout>
</template>
