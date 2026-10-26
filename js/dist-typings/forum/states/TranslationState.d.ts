import type Model from 'flarum/common/Model';
import type TranslationRecord from '../../common/models/TranslationRecord';
/**
 * Per-model translation UI state. One instance per subject model, kept in a
 * module-level WeakMap so a post that scrolls out of the stream and back in
 * keeps its open translation, and is reclaimed together with the model.
 */
export default class TranslationState<T extends TranslationRecord = TranslationRecord> {
    readonly subject: Model;
    /**
     * API path segment of the subject resource: `posts`, `discussions`, `polls`.
     */
    readonly resource: string;
    translation: T | null;
    language: string | null;
    loading: boolean;
    showing: boolean;
    /**
     * Locales the API refused for this subject (already in that language, or
     * not offered for it); dropped from the translate menu.
     */
    readonly unavailable: Set<string>;
    /**
     * Subject freshness when `translation` was fetched; a newer subject (e.g. an edited post) refetches.
     */
    protected fetchedFor: Date | null;
    /**
     * Bumped on every change; mixed into retained-subtree checks.
     */
    version: number;
    protected static states: WeakMap<Model, TranslationState<any>>;
    static for<T extends TranslationRecord>(subject: Model, resource: string): TranslationState<T>;
    /**
     * Locales refused for a model so far; empty when it has no state yet.
     */
    static unavailableFor(subject: Model): ReadonlySet<string>;
    /**
     * The existing state for a model, without creating one.
     */
    static peek<T extends TranslationRecord>(subject: Model): TranslationState<T> | undefined;
    constructor(subject: Model, resource: string);
    /**
     * The translation currently on display, if any.
     */
    active(): T | null;
    url(): string;
    /**
     * Called when the reader asks for a translation (not on refresh), before any
     * request. Lets a first post bring its discussion title along.
     */
    onTranslate: ((locale: string) => void) | null;
    /**
     * @param quiet Suppress the error alert: for translations the reader did not ask for directly.
     */
    translate(locale: string, refresh?: boolean, quiet?: boolean): Promise<T | null>;
    protected subjectChanged(): boolean;
    refresh(): Promise<T | null>;
    /**
     * Show a translation fetched elsewhere (the thread's bulk lookup) as if this state had requested it.
     */
    adopt(translation: T): void;
    close(redraw?: boolean): void;
    protected changed(redraw?: boolean): void;
}
/**
 * A 422 pointing at the `locale` attribute: this subject cannot be translated
 * into that locale (it is already in it, or it is not offered). Transient
 * failures point at `/data/attributes/translation` instead.
 */
export declare function isLocaleRejection(error: unknown): boolean;
