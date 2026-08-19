/**
 * Sibling-URL construction for the language switcher.
 *
 * A second implementation of App\Modules\Core\Support\LocalizedRoutes, and the
 * same risk the hreflang defect came from: two implementations of one rule
 * drifting apart. Mitigated the same way as the WKT writer — this side is the
 * narrower one, it consumes the strategy the server sends rather than assuming
 * it, and the pair is checked against each other.
 *
 * The strategy is never hardcoded here. If `prefix_except_default` becomes
 * `prefix_all` in config, both sides follow without a code change.
 */

export type UrlStrategy = 'prefix_except_default' | 'prefix_all';

/** Remove a leading locale segment, if the path carries one. */
export function stripLocale(path: string, locales: string[]): string {
    const segments = path.split('/').filter(Boolean);

    if (segments.length > 0 && locales.includes(segments[0])) {
        segments.shift();
    }

    return '/' + segments.join('/');
}

/** Whether a locale takes a URL prefix under the given strategy. */
export function isPrefixed(locale: string, defaultLocale: string, strategy: UrlStrategy): boolean {
    return strategy === 'prefix_all' || locale !== defaultLocale;
}

/**
 * The equivalent path for `target`, preserving query and hash.
 *
 * The current locale prefix is stripped before the new one is applied, so
 * switching twice does not accumulate prefixes.
 */
export function localizedPath(
    currentPath: string,
    target: string,
    defaultLocale: string,
    locales: string[],
    strategy: UrlStrategy = 'prefix_except_default',
): string {
    const [pathOnly, ...rest] = currentPath.split(/([?#])/);
    const suffix = rest.join('');
    const bare = stripLocale(pathOnly, locales);
    const prefix = isPrefixed(target, defaultLocale, strategy) ? '/' + target : '';
    const combined = (prefix + (bare === '/' ? '' : bare)) || '/';

    return combined + suffix;
}
