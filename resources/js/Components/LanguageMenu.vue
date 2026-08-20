<script setup lang="ts">
import { computed } from 'vue';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import AppMenu from '@/Components/ui/AppMenu.vue';
import LocaleFlag from '@/Components/LocaleFlag.vue';
import { useLocale } from '@/Composables/useLocale';
import { t } from '@/lib/i18n';

/*
 * The compact language control (redesign Wave 1 §2), replacing the segmented
 * three-button row that consumed a third of the top bar — and the two inline
 * copies of it the admin and auth layouts had grown.
 *
 * The switching MECHANISM is untouched, which is the whole point of reusing
 * `switchTo`: it still navigates to the sibling URL on a localized route
 * (preserving path, query and hash) and still POSTs the session locale
 * everywhere else. Only the presentation changed — closed, it is one flag
 * and a caret; open, each option is the language's own native name with the
 * flag as a visual anchor and a check on the active row. `aria-current`
 * marks the active language for the same reason it did on the segmented
 * control: colour alone is not an indicator.
 *
 * Each option carries its own `lang` and `dir`, so English renders LTR
 * inside an RTL panel and کوردی renders RTL inside an LTR one — the native
 * name is the label, and a native name deserves its native direction.
 */
withDefaults(defineProps<{ showLabel?: boolean }>(), { showLabel: true });

const { current, available, switchTo } = useLocale();

const currentOption = computed(() => available.value.find((locale) => locale.code === current.value));
</script>

<template>
    <AppMenu
        :label="t('nav.language')"
        data-testid="language-menu"
        trigger-class="min-h-11 min-w-11 gap-1.5 px-2 text-ink-muted hover:bg-surface-sunken hover:text-ink"
    >
        <template #trigger>
            <LocaleFlag :code="current" class="h-4 w-6 shrink-0" />
            <span v-if="showLabel" class="hidden text-xs font-medium xl:inline" :lang="current">
                {{ currentOption?.native }}
            </span>
            <AppIcon name="chevron-down" class="h-3.5 w-3.5 shrink-0" />
        </template>

        <template #default="{ close }">
            <button
                v-for="locale in available"
                :key="locale.code"
                type="button"
                class="flex min-h-11 w-full items-center gap-2.5 rounded-[0.6rem] px-3 text-sm
                       transition-colors duration-200 ease-calm
                       focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
                :class="locale.code === current
                    ? 'font-medium text-ink'
                    : 'text-ink-muted hover:bg-surface-sunken hover:text-ink'"
                :aria-current="locale.code === current ? 'true' : undefined"
                :lang="locale.code"
                :dir="locale.direction"
                @click="close(); switchTo(locale.code);"
            >
                <LocaleFlag :code="locale.code" class="h-4 w-6 shrink-0" />
                <span class="flex-1 text-start">{{ locale.native }}</span>
                <AppIcon
                    v-if="locale.code === current"
                    name="check"
                    class="h-4 w-4 shrink-0 text-accent-strong"
                />
            </button>
        </template>
    </AppMenu>
</template>
