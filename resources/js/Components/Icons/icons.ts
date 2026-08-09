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
    | 'check'
    | 'chevron'
    | 'close'
    | 'invest'
    | 'map'
    | 'market'
    | 'menu'
    | 'news'
    | 'offers'
    | 'portfolio'
    | 'projects'
    | 'send'
    | 'spark'
    | 'trend-down'
    | 'trend-flat'
    | 'trend-up'
    | 'user';

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
};
