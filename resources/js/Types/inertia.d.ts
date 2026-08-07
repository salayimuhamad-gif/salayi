export type LocaleCode = 'ckb' | 'ar' | 'en';

export interface LocaleOption {
    code: LocaleCode;
    native: string;
    direction: 'rtl' | 'ltr';
}

export interface ActingCompanyProps {
    current_id: number | null;
    /** Explicit mode; a null current_id alone cannot express platform intent. */
    mode: 'platform' | 'company';
    may_use_platform: boolean;
    must_choose: boolean;
    options: Array<{ id: number; name: string }>;
}

export interface SharedPageProps {
    /*
     * Map configuration. `google_key` is intentionally absent from the server
     * payload — the adapter falls back to MapLibre, which is the safe default
     * rather than a degraded one, and shipping a key to every page would put
     * it in view source.
     */
    map: { provider: string; style_url: string | null };
    /*
     * The acting-company context, shared from HandleInertiaRequests. Declared
     * here rather than cast through `unknown` at the call site — a cast makes
     * the shape unverifiable, which is how a shared prop drifts away from the
     * server that produces it.
     */
    acting_company: ActingCompanyProps | null;
    /*
     * Inertia's PageProps constrains to an indexable type. Without this every
     * usePage<SharedPageProps>() call fails to compile — which nothing caught
     * until the first real type check, because php -l never sees this file.
     */
    [key: string]: unknown;

    auth: {
        user: {
            id: number;
            name: string;
            locale: LocaleCode;
            is_admin: boolean;
        } | null;
        permissions: string[];
    };
    locale: {
        current: LocaleCode;
        direction: 'rtl' | 'ltr';
        available: LocaleOption[];
        default: LocaleCode;
        url_strategy: string;
        route_is_localized: boolean;
    };
    branding: Record<string, string | null>;
    features: Record<string, boolean>;
    flash: {
        success: string | null;
        error: string | null;
        /** Set by controllers via `->with('status', ...)`. */
        status: string | null;
    };
    app: {
        name: string;
        version: string;
    };
    /*
     * The unread badge count only. The notifications themselves are never in
     * the shared payload — see HandleInertiaRequests for why.
     */
    notifications: {
        unread: number;
    };
}

declare module '@inertiajs/core' {
    /*
     * Declaration merging is the only way to widen Inertia's own PageProps, and
     * an interface that merely extends is exactly the shape that requires — a
     * type alias cannot merge into a module's existing interface. The rule is
     * therefore disabled for this line specifically rather than project-wide.
     */
    // eslint-disable-next-line @typescript-eslint/no-empty-object-type
    interface PageProps extends SharedPageProps {}
}

export {};
