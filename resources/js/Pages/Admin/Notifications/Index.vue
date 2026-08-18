<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import AppEmptyState from '@/Components/ui/AppEmptyState.vue';
import AppButton from '@/Components/ui/AppButton.vue';
import { t } from '@/lib/i18n';
import AppPagination from '@/Components/ui/AppPagination.vue';

interface Row {
    id: number;
    key: string;
    subject: string;
    preview: string;
    priority: string;
    is_read: boolean;
    action_url: string | null;
    created_at: string | null;
}

const props = defineProps<{
    notifications: {
        data: Row[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        current_page: number;
        last_page: number;
    };
    filters: { filter: 'all' | 'unread' };
    counts: { unread: number; total: number };
}>();

const hasUnread = computed(() => props.counts.unread > 0);

function setFilter(filter: 'all' | 'unread'): void {
    router.get('/admin/notifications', { filter }, { preserveState: true, replace: true });
}

function markAll(): void {
    router.post('/admin/notifications/read-all', {}, { preserveScroll: true });
}

function markOne(id: number): void {
    router.post(`/admin/notifications/${id}/read`, {}, { preserveScroll: true });
}

/*
 * Relative for anything recent, absolute after a day. "3 hours ago" is what
 * someone wants for a notice they might act on; for one from last month the
 * date is more useful than counting weeks.
 */
function when(iso: string | null): string {
    if (!iso) return '';

    const then = new Date(iso);
    const minutes = Math.round((Date.now() - then.getTime()) / 60000);

    if (minutes < 1) return t('notifications.center.time_now');
    if (minutes < 60) return t('notifications.center.time_minutes', { count: minutes });
    if (minutes < 1440) return t('notifications.center.time_hours', { count: Math.floor(minutes / 60) });

    return then.toLocaleDateString();
}

const priorityTone = (priority: string): string => ({
    urgent: 'bg-negative/10 text-negative',
    high: 'bg-caution/10 text-caution',
}[priority] ?? '');
</script>

<template>
    <Head :title="t('notifications.center.title')" />

    <AdminLayout>
        <template #title>{{ t('notifications.center.title') }}</template>

        <!-- Controls. Stacked on a phone, inline from `sm` up: the filter and
             the bulk action are both one-handed targets on mobile. -->
        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div
                class="inline-flex rounded-card border border-line bg-surface-raised p-1"
                role="group"
                :aria-label="t('notifications.center.title')"
            >
                <button
                    v-for="option in (['all', 'unread'] as const)"
                    :key="option"
                    type="button"
                    class="rounded-card px-3 py-1.5 text-sm transition-colors
                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                    :class="filters.filter === option
                        ? 'bg-brand text-white'
                        : 'text-ink-muted hover:bg-surface-sunken'"
                    :aria-pressed="filters.filter === option"
                    @click="setFilter(option)"
                >
                    {{ option === 'all'
                        ? t('notifications.center.filter_all')
                        : t('notifications.center.filter_unread') }}
                    <span v-if="option === 'unread' && counts.unread > 0" class="numeral ms-1">
                        ({{ counts.unread }})
                    </span>
                </button>
            </div>

            <div class="flex items-center gap-2">
                <AppButton v-if="hasUnread" variant="secondary" size="sm" @click="markAll">
                    {{ t('notifications.center.mark_all_read') }}
                </AppButton>

                <Link
                    href="/admin/notifications/preferences"
                    class="rounded-card px-3 py-1.5 text-sm text-ink-muted transition-colors
                           hover:bg-surface-sunken hover:text-ink
                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                >
                    {{ t('notifications.preferences.title') }}
                </Link>
            </div>
        </div>

        <AppEmptyState
            v-if="notifications.data.length === 0"
            :title="filters.filter === 'unread'
                ? t('notifications.center.empty_unread')
                : t('notifications.center.empty')"
            :description="t('notifications.center.empty_hint')"
        />

        <div v-else class="mh-card overflow-hidden">
            <ul class="divide-y divide-line">
                <li
                    v-for="item in notifications.data"
                    :key="item.id"
                    class="relative"
                    :class="item.is_read ? '' : 'bg-brand/[0.03]'"
                >
                    <!-- The whole row is the link. A small chevron target is
                         hard to hit on a phone; the row is not. -->
                    <Link
                        :href="`/admin/notifications/${item.id}`"
                        class="flex items-start gap-3 px-4 py-3.5 transition-colors
                               hover:bg-surface-sunken focus-visible:outline-none
                               focus-visible:ring-2 focus-visible:ring-accent sm:px-5"
                    >
                        <!-- Unread marker. Shape and position, not colour
                             alone, so it survives a colour-vision difference. -->
                        <span
                            class="mt-1.5 h-2 w-2 shrink-0 rounded-full"
                            :class="item.is_read ? 'bg-transparent' : 'bg-accent'"
                            aria-hidden="true"
                        />

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                <p
                                    class="min-w-0 flex-1 truncate text-sm text-ink"
                                    :class="item.is_read ? '' : 'font-semibold'"
                                >
                                    {{ item.subject }}
                                </p>

                                <span
                                    v-if="priorityTone(item.priority)"
                                    :class="['shrink-0 rounded-full px-2 py-0.5 text-xs', priorityTone(item.priority)]"
                                >
                                    {{ t(`notifications.priorities.${item.priority}`) }}
                                </span>
                            </div>

                            <p class="mt-1 line-clamp-2 text-xs leading-relaxed text-ink-muted">
                                {{ item.preview }}
                            </p>

                            <time
                                v-if="item.created_at"
                                :datetime="item.created_at"
                                class="numeral mt-1.5 block text-xs text-ink-faint"
                            >
                                {{ when(item.created_at) }}
                            </time>
                        </div>
                    </Link>

                    <!-- Sits outside the Link: a button nested in an anchor is
                         invalid markup and the click target becomes ambiguous. -->
                    <button
                        v-if="!item.is_read"
                        type="button"
                        class="absolute end-3 top-3 rounded-card px-2 py-1 text-xs text-ink-faint
                               transition-colors hover:bg-surface-sunken hover:text-ink
                               focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                        :aria-label="t('notifications.center.mark_read')"
                        @click.stop.prevent="markOne(item.id)"
                    >
                        ✓
                    </button>
                </li>
            </ul>
        </div>

        <AppPagination v-if="notifications.last_page > 1" :links="notifications.links" />
    </AdminLayout>
</template>
