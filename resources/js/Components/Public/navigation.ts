import type { IconName } from '@/Components/Icons/icons';

/**
 * One resolved public navigation entry.
 *
 * Resolved, not declarative: by the time an item has this shape the feature
 * flag has already been consulted and the href has already been through
 * `localized()`. PublicLayout owns that resolution and the sidebar and the
 * mobile drawer both consume the result, so the two navigations cannot drift
 * apart — which is the failure this type exists to make impossible.
 */
export interface PublicNavItem {
    /** Translation key suffix under `nav.public.` and the icon lookup. */
    key: string;
    /** Already locale-prefixed. */
    href: string;
    icon: IconName;
    /** True for the surface the visitor is currently on. */
    active: boolean;
    /** The advisor is the product's centre of gravity and is marked as such. */
    emphasised: boolean;
}
