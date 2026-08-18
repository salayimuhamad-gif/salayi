<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppButton from '@/Components/ui/AppButton.vue';
import AppAlert from '@/Components/ui/AppAlert.vue';
import { t } from '@/lib/i18n';

const props = defineProps<{
    projectId: number;
    status: string;
    readiness: { ok: boolean; blockers: string[] };
    allowedTransitions: string[];
    canPublish: boolean;
}>();

/*
 * The Step 2 publish gate, made visible.
 *
 * Project::publicationReadiness() returns named blockers. Showing them while
 * the form is still open is the whole point: an administrator who presses
 * Publish and is refused has to guess what to fix, and "this project cannot be
 * published" without a reason is what generates a support call.
 */
const canPublishNow = computed(() =>
    props.canPublish
    && props.readiness.ok
    && props.allowedTransitions.includes('published'),
);

const nextStates = computed(() =>
    props.allowedTransitions.filter((s) => s !== 'published' && s !== 'archived'),
);

function move(status: string): void {
    router.post(`/admin/projects/${props.projectId}/transition`, { status }, { preserveScroll: true });
}
</script>

<template>
    <section class="mh-card p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="mh-label">{{ t('projects.publication.status') }}</p>
                <p class="mt-1 font-display text-base font-semibold text-ink">
                    {{ t(`projects.publication_statuses.${status}`) }}
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <AppButton
                    v-for="next in nextStates"
                    :key="next"
                    variant="secondary"
                    size="sm"
                    @click="move(next)"
                >
                    {{ t(`projects.publication_statuses.${next}`) }}
                </AppButton>

                <AppButton
                    v-if="allowedTransitions.includes('published')"
                    size="sm"
                    :disabled="!canPublishNow"
                    @click="move('published')"
                >
                    {{ t('projects.publication.publish') }}
                </AppButton>
            </div>
        </div>

        <AppAlert v-if="!readiness.ok" variant="warning" class="mt-5">
            <p class="font-medium">{{ t('projects.publication.blocked') }}</p>
            <ul class="mt-2 space-y-1">
                <li v-for="blocker in readiness.blockers" :key="blocker" class="flex gap-2 text-sm">
                    <span aria-hidden="true">·</span>
                    <span>{{ t(`projects.blockers.${blocker}`) }}</span>
                </li>
            </ul>
        </AppAlert>

        <AppAlert v-else-if="!canPublish" variant="info" class="mt-5">
            {{ t('projects.publication.no_permission') }}
        </AppAlert>

        <AppAlert v-else-if="!allowedTransitions.includes('published')" variant="info" class="mt-5">
            {{ t('projects.publication.needs_review') }}
        </AppAlert>
    </section>
</template>
