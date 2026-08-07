<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t } from '@/lib/i18n';

/*
 * Where the visitor lands after a project is created (spec 12.1).
 *
 * The hand-offs used to sit on the review screen, conditional on a project id
 * that only existed after submission — by which point the draft was a
 * submitted audit record and could not be reopened. So the links rendered
 * never. Dead conditional UI is worse than none: it looks like the feature is
 * there.
 *
 * Every destination is gated on a real capability. A link somebody cannot
 * follow is a worse answer than saying why it is missing.
 */
defineProps<{
    project: { id: number; name: string | null; publication_status: string | null };
    can: { edit: boolean; media: boolean; ratings: boolean; publish: boolean; wizard: boolean };
}>();
</script>

<template>
    <Head :title="t('projects.wizard.creation.done_title')" />

    <AdminLayout>
        <template #title>{{ t('projects.wizard.creation.done_title') }}</template>

        <div class="mh-card max-w-2xl p-5">
            <p class="text-lg font-medium text-ink">{{ project.name }}</p>

            <!-- A new project is never born published, whatever the author's
                 permissions. Saying so here prevents the "why isn't it on the
                 site?" question entirely. -->
            <AppAlert
                class="mt-3"
                variant="info"
                :message="t('projects.wizard.creation.not_published_notice')"
            />

            <div class="mt-5 space-y-3">
                <div>
                    <Link v-if="can.edit" :href="`/admin/projects/${project.id}/edit`">
                        <AppButton>{{ t('projects.wizard.creation.done_open') }}</AppButton>
                    </Link>
                    <p v-else class="text-sm text-ink-faint">
                        {{ t('projects.wizard.creation.state_denied') }}
                    </p>
                </div>

                <div>
                    <Link v-if="can.media" :href="`/admin/projects/${project.id}/media`">
                        <AppButton variant="secondary">
                            {{ t('projects.wizard.creation.done_media') }}
                        </AppButton>
                    </Link>
                    <p v-else class="text-sm text-ink-faint">
                        {{ t('projects.wizard.creation.state_denied') }}
                    </p>
                </div>

                <div>
                    <Link v-if="can.ratings" :href="`/admin/projects/${project.id}/ratings`">
                        <AppButton variant="secondary">
                            {{ t('projects.wizard.creation.ratings_handoff') }}
                        </AppButton>
                    </Link>
                    <p v-else class="text-sm text-ink-faint">
                        {{ t('projects.wizard.creation.state_denied') }}
                    </p>
                </div>

                <div>
                    <!-- Publication is its own reviewed transition, and it
                         needs projects.publish. A company account manager
                         submits; the platform decides. -->
                    <Link v-if="can.publish" :href="`/admin/projects/${project.id}/edit`">
                        <AppButton variant="secondary">
                            {{ t('projects.wizard.creation.publication_handoff') }}
                        </AppButton>
                    </Link>
                    <p v-else class="text-sm text-ink-muted">
                        {{ t('projects.wizard.creation.publication_hint') }}
                    </p>
                </div>
            </div>

            <Link v-if="can.wizard" href="/admin/projects/wizard" class="mt-6 inline-block">
                <AppButton variant="secondary">
                    {{ t('projects.wizard.creation.done_another') }}
                </AppButton>
            </Link>
        </div>
    </AdminLayout>
</template>
