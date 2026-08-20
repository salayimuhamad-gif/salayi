<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppIcon from '@/Components/Icons/AppIcon.vue';
import { t } from '@/lib/i18n';

/*
 * One published project (§8.3).
 *
 * The server sends five fields and no more: slug, name, area, project_type,
 * construction_status. There is no image, no price, no ROI, no bedroom count,
 * no unit size, no rating — so none of those appear here, and none of them are
 * approximated. §3.2 and §8.3 both say it; HomeController says it too, in a
 * comment explaining that a card price would be an unqualified number of
 * exactly the kind the indices above it take care to avoid.
 *
 * The cover is therefore a gradient and a type glyph, not a photograph. That is
 * a deliberate choice over stock imagery: a generic tower photo on a card
 * labelled with a real Erbil project name is a claim about that project's
 * appearance, and it would be a false one. An abstract field cannot be
 * misread as a photograph of anything.
 *
 * The whole card is one link with one accessible name — the project name — so
 * a screen-reader user is not read three separate links to the same place.
 */
withDefaults(defineProps<{
    project: {
        slug: string;
        name: string;
        area: string | null;
        project_type: string | null;
        construction_status: string | null;
    };
    href: string;
    /**
     * Wave 2B: which of the three decorative art gradients heads the card.
     * Rotated by the caller (index % 3) purely for rhythm — the art encodes
     * nothing about the project and can never be mistaken for photography.
     */
    variant?: number;
}>(), { variant: 0 });

const artClass = ['mh-art-a', 'mh-art-b', 'mh-art-c'] as const;
</script>

<template>
    <article class="mh-lux-card mh-lux-card-interactive mh-lift-hover overflow-hidden !rounded-glass">
        <Link
            :href="href"
            class="block focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent"
        >
            <!-- Decorative only: aria-hidden, and nothing in it encodes data. -->
            <div
                class="relative flex h-[8.125rem] items-center justify-center border-b border-line text-white/85"
                :class="artClass[((variant % 3) + 3) % 3]"
                aria-hidden="true"
            >
                <AppIcon name="building" class="h-8 w-8 opacity-90" />
            </div>

            <div class="p-5">
                <h3 class="font-medium text-ink">{{ project.name }}</h3>

                <p v-if="project.area" class="mt-1 flex items-center gap-1.5 text-xs text-ink-muted">
                    <AppIcon name="areas" class="h-3.5 w-3.5 shrink-0" />
                    {{ project.area }}
                </p>

                <div
                    v-if="project.project_type || project.construction_status"
                    class="mt-3 flex flex-wrap gap-1.5"
                >
                    <span v-if="project.project_type" class="mh-lux-chip">
                        {{ t(`projects.types.${project.project_type}`) }}
                    </span>
                    <span v-if="project.construction_status" class="mh-lux-chip">
                        {{ t(`projects.construction_statuses.${project.construction_status}`) }}
                    </span>
                </div>
            </div>
        </Link>
    </article>
</template>
