import type { IconName } from '@/Components/Icons/icons';

/*
 * Service-group -> glyph (Map Phase 2's area summary, shared in Phase 3 by
 * the Area profile and the map's Area Intelligence card so the two surfaces
 * cannot drift). Everything the server can send is covered; a group an
 * admin invents later degrades to the neutral pin.
 */
const SERVICE_ICONS: Record<string, IconName> = {
    education: 'education',
    health: 'health',
    shopping: 'bag',
    transport: 'bus',
    recreation: 'tree',
    worship: 'dome',
    civic: 'landmark',
    finance: 'banknote',
    hospitality: 'cup',
    employment: 'portfolio',
    other: 'map-pin',
};

export function serviceIcon(group: string): IconName {
    return SERVICE_ICONS[group] ?? 'map-pin';
}
