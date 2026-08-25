/*
 * The public surface's icon set, inline and local.
 *
 * §10.1 asks for no new dependency, and the reason holds independently: an
 * icon package would ship a few hundred kilobytes so that one layout could use
 * nineteen glyphs, on a site whose visitors are overwhelmingly on Erbil mobile
 * data.
 *
 * Every glyph is drawn on the same 24-unit grid at the same 1.6 stroke width,
 * because the alternative — icons lifted from different sets — is the visual
 * tell that separates a designed interface from an assembled one.
 *
 * Paths only, no <circle> or <rect>, so a single <path> renders every glyph and
 * AppIcon.vue needs no branching in its template.
 */
export type IconName =
    | 'advisor'
    | 'areas'
    | 'arrow-end'
    | 'bell'
    | 'building'
    | 'chart-line'
    | 'check'
    | 'chevron'
    | 'chevron-down'
    | 'close'
    | 'compass'
    | 'external'
    | 'filter'
    | 'globe'
    | 'home'
    | 'invest'
    | 'layers'
    | 'locate'
    | 'map'
    | 'map-pin'
    | 'market'
    | 'menu'
    | 'moon'
    | 'more'
    | 'news'
    | 'offers'
    | 'portfolio'
    | 'projects'
    | 'search'
    | 'send'
    | 'shield-check'
    | 'spark'
    | 'sun'
    | 'trend-down'
    | 'trend-flat'
    | 'trend-up'
    | 'user'
    | 'x-circle';

export const iconPaths: Record<IconName, string> = {
    advisor:
        'M12 3.5a3.2 3.2 0 0 1 3.2 3.2v.6h.9a2.4 2.4 0 0 1 2.4 2.4v6.2a2.4 2.4 0 0 1-2.4 2.4H7.9a2.4 2.4 0 0 1-2.4-2.4V9.7a2.4 2.4 0 0 1 2.4-2.4h.9v-.6A3.2 3.2 0 0 1 12 3.5Zm0 0V2M9.4 12.2v1.4m5.2-1.4v1.4M9.8 16.6h4.4M3.6 11.4v3.2m16.8-3.2v3.2',
    areas: 'M12 21s6.4-5.1 6.4-9.6a6.4 6.4 0 1 0-12.8 0C5.6 15.9 12 21 12 21Zm0-11.8a2.2 2.2 0 1 1 0 4.4 2.2 2.2 0 0 1 0-4.4Z',
    'arrow-end': 'M4.5 12h14m0 0-5.2-5.2M18.5 12l-5.2 5.2',
    check: 'm5 12.6 4.5 4.4L19 6.8',
    chevron: 'm9.5 5.5 6.4 6.5-6.4 6.5',
    close: 'M6 6l12 12M18 6 6 18',
    // Towers stepping upward: built investment, growing. Same 24-grid,
    // single-path, 1.75-stroke discipline as the rest of the set.
    invest: 'M3.8 20.2h16.4M6.2 20.2v-6.6h3.4v6.6m1.2 0V9.4h3.4v10.8m1.2 0V5.2h3.4v15M6.2 13.6l3.4-3.2m1.2-1 3.4-3.2',
    map: 'M9.2 4.4 3.6 6.9v12.7l5.6-2.5m0-12.7 5.6 2.5m-5.6-2.5v12.7m5.6-10.2 5.6-2.5v12.7l-5.6 2.5m0-12.7v12.7m0 0-5.6-2.5',
    market: 'M3.6 19.4h16.8M6.4 19.4v-5.6m4.2 5.6V8.2m4.2 11.2v-7.7m4.2 7.7V5.4',
    menu: 'M3.8 6.6h16.4M3.8 12h16.4M3.8 17.4h16.4',
    news: 'M5.4 5.4h10.2v13.2H5.4Zm10.2 3.4h3v7.4a1.8 1.8 0 0 1-1.8 1.8h-1.2ZM8 8.8h5M8 12h5M8 15.2h3',
    offers: 'M11.1 3.9H19a1.1 1.1 0 0 1 1.1 1.1v7.9l-8.4 8.4a1.1 1.1 0 0 1-1.6 0l-6.3-6.3a1.1 1.1 0 0 1 0-1.6ZM16 8h.01',
    portfolio: 'M4.2 8.6h15.6v10.2H4.2Zm4.6 0V6.4a1.4 1.4 0 0 1 1.4-1.4h3.6a1.4 1.4 0 0 1 1.4 1.4v2.2M4.2 13h15.6',
    projects:
        'M4.4 20.2V6.1l7-2.3v16.4m0 0h8.2V10.6h-8.2M7.4 8.8h1M7.4 12h1M7.4 15.2h1m6.6-1.4h1.4m-1.4 3.2h1.4',
    send: 'M4.6 11.9 19.4 5l-6.9 14.8-1.7-6.2Z',
    spark: 'M12 3.6 13.7 9l5.4 1.7-5.4 1.7L12 17.8l-1.7-5.4L4.9 10.7 10.3 9ZM18.6 16.4l.7 2.1 2.1.7-2.1.7-.7 2.1-.7-2.1-2.1-.7 2.1-.7Z',
    'trend-down': 'M4.6 8.4 12 15.8l3.1-3.1 4.3 4.3m0 0v-4.4m0 4.4H15',
    'trend-flat': 'M4.6 12h14.8m0 0-3.2-3.2M19.4 12l-3.2 3.2',
    'trend-up': 'M4.6 15.6 12 8.2l3.1 3.1 4.3-4.3m0 0v4.4m0-4.4H15',
    user: 'M12 12.2a3.7 3.7 0 1 0 0-7.4 3.7 3.7 0 0 0 0 7.4Zm0 0c-3.6 0-6.5 2.3-6.5 5.2v1.8h13v-1.8c0-2.9-2.9-5.2-6.5-5.2Z',
    /*
     * Redesign additions — same 24-grid, same stroke discipline, paths only.
     * Directional glyphs (external, locate crosshair ticks are symmetric;
     * chevron-down is vertical) note their mirror rules at the call site.
     */
    bell: 'M12 4.2a5.2 5.2 0 0 1 5.2 5.2c0 3.4.9 5 1.8 6.1H5c.9-1.1 1.8-2.7 1.8-6.1A5.2 5.2 0 0 1 12 4.2Zm-2 13.6a2 2 0 0 0 4 0',
    building: 'M5.2 20.2V5.4a1.2 1.2 0 0 1 1.2-1.2h7a1.2 1.2 0 0 1 1.2 1.2v14.8m0-9h3a1.2 1.2 0 0 1 1.2 1.2v7.8M3.6 20.2h16.8M8.2 7.6h1.2m2.4 0H13M8.2 10.8h1.2m2.4 0H13M8.2 14h1.2m2.4 0H13m-1.6 6.2v-3h-3v3',
    'chart-line': 'M4 4.6v14.8h16M7.4 15.2l3.4-4 3 2.4 4.6-5.8',
    'chevron-down': 'm5.5 9.5 6.5 6.4 6.5-6.4',
    compass: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm3.4-12.4-1.9 5.4-5.4 1.9 1.9-5.4Z',
    external: 'M10 5.4H6.2A1.8 1.8 0 0 0 4.4 7.2v10.6a1.8 1.8 0 0 0 1.8 1.8h10.6a1.8 1.8 0 0 0 1.8-1.8V14m-6.2-9.6h6.2m0 0v6.2m0-6.2L10.4 12.6',
    filter: 'M4.2 6h15.6l-6 7v5.4l-3.6 1.8V13Z',
    globe: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Zm0-18c-2.4 2.2-3.8 5.4-3.8 9s1.4 6.8 3.8 9c2.4-2.2 3.8-5.4 3.8-9s-1.4-6.8-3.8-9ZM3.6 9.4h16.8M3.6 14.6h16.8',
    home: 'm4.2 11.2 7.8-7 7.8 7M6.2 9.6v9.4a1.2 1.2 0 0 0 1.2 1.2h2.8v-5.4h3.6v5.4h2.8a1.2 1.2 0 0 0 1.2-1.2V9.6',
    layers: 'm12 3.8 8.4 4.4L12 12.6 3.6 8.2Zm-8.4 8.6L12 16.8l8.4-4.4M3.6 16.4 12 20.8l8.4-4.4',
    locate: 'M12 19.4a7.4 7.4 0 1 0 0-14.8 7.4 7.4 0 0 0 0 14.8Zm0-4.6a2.8 2.8 0 1 0 0-5.6 2.8 2.8 0 0 0 0 5.6ZM12 2.2v2.4m0 14.8v2.4M2.2 12h2.4m14.8 0h2.4',
    'map-pin': 'M12 21.2s6.8-6 6.8-11a6.8 6.8 0 1 0-13.6 0c0 5 6.8 11 6.8 11Zm0-8.4a2.6 2.6 0 1 0 0-5.2 2.6 2.6 0 0 0 0 5.2Z',
    /*
     * Theme control pair. `moon` matches the admin header's crescent so the
     * one action reads the same on both shells; `sun` is its daylight
     * counterpart. `more` is three round-cap dots for the overflow menu.
     */
    moon: 'M20.2 13.1A8.3 8.3 0 1 1 10.9 3.8a6.5 6.5 0 0 0 9.3 9.3Z',
    more: 'M5.2 12h.01M12 12h.01M18.8 12h.01',
    sun: 'M12 16.2a4.2 4.2 0 1 0 0-8.4 4.2 4.2 0 0 0 0 8.4ZM12 2.9v2.2m0 13.8v2.2M2.9 12h2.2m13.8 0h2.2M5.6 5.6l1.5 1.5m9.8 9.8 1.5 1.5m0-12.8-1.5 1.5M7.1 16.9l-1.5 1.5',
    search: 'M10.8 17.2a6.4 6.4 0 1 0 0-12.8 6.4 6.4 0 0 0 0 12.8Zm4.8-1.6 4.6 4.6',
    'shield-check': 'M12 3.4 5 6v5.2c0 4.6 3 8 7 9.4 4-1.4 7-4.8 7-9.4V6Zm-3.2 8.8 2.3 2.3 4.1-4.6',
    'x-circle': 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18ZM9.2 9.2l5.6 5.6m0-5.6-5.6 5.6',
};
