/**
 * Ziggy's global `route()` helper, injected by the @routes Blade directive.
 * Declared here because the runtime function has no module to import from.
 */
declare function route(name: string, params?: Record<string, unknown> | string | number): string;

interface Window {
    route: typeof route;
}
