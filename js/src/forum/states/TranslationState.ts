import app from 'flarum/forum/app';
import type Model from 'flarum/common/Model';
import type RequestError from 'flarum/common/utils/RequestError';
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

  translation: T | null = null;
  language: string | null = null;
  loading = false;
  showing = false;

  /**
   * Locales the API refused for this subject (already in that language, or
   * not offered for it); dropped from the translate menu.
   */
  readonly unavailable = new Set<string>();

  /**
   * Subject freshness when `translation` was fetched; a newer subject (e.g. an edited post) refetches.
   */
  protected fetchedFor: Date | null = null;

  /**
   * Bumped on every change; mixed into retained-subtree checks.
   */
  version = 0;

  protected static states = new WeakMap<Model, TranslationState<any>>();

  static for<T extends TranslationRecord>(subject: Model, resource: string): TranslationState<T> {
    let state = TranslationState.states.get(subject);

    if (!state) {
      state = new TranslationState<T>(subject, resource);
      TranslationState.states.set(subject, state);
    }

    return state;
  }

  /**
   * Locales refused for a model so far; empty when it has no state yet.
   */
  static unavailableFor(subject: Model): ReadonlySet<string> {
    return TranslationState.states.get(subject)?.unavailable ?? new Set<string>();
  }

  /**
   * The existing state for a model, without creating one.
   */
  static peek<T extends TranslationRecord>(subject: Model): TranslationState<T> | undefined {
    return TranslationState.states.get(subject);
  }

  constructor(subject: Model, resource: string) {
    this.subject = subject;
    this.resource = resource;
  }

  /**
   * The translation currently on display, if any.
   */
  active(): T | null {
    return this.showing && this.translation?.language() === this.language ? this.translation : null;
  }

  url(): string {
    return `${app.forum.attribute<string>('apiUrl')}/${this.resource}/${this.subject.id()}/translate`;
  }

  /**
   * Called when the reader asks for a translation (not on refresh), before any
   * request. Lets a first post bring its discussion title along.
   */
  onTranslate: ((locale: string) => void) | null = null;

  /**
   * @param quiet Suppress the error alert: for translations the reader did not ask for directly.
   */
  translate(locale: string, refresh = false, quiet = false): Promise<T | null> {
    if (this.loading) return Promise.resolve(null);

    if (!refresh) this.onTranslate?.(locale);

    // A translation already fetched for this locale is shown again without a request.
    if (!refresh && this.translation && this.language === locale && !this.subjectChanged()) {
      this.showing = true;
      this.changed();

      return Promise.resolve(this.translation);
    }

    const previous = { language: this.language, showing: this.showing };

    this.loading = true;
    this.language = locale;
    this.showing = true;
    this.changed();

    const attributes: { locale: string; refresh?: true } = { locale };
    if (refresh) attributes.refresh = true;

    return app
      .request<any>({
        method: 'POST',
        url: this.url(),
        body: { data: { attributes } },
        ...(quiet ? { errorHandler: () => {} } : {}),
      })
      .then((payload) => {
        this.translation = app.store.pushPayload<T>(payload);
        this.fetchedFor = this.subject.freshness;
        this.loading = false;
        this.changed();

        return this.translation;
      })
      .catch((error: unknown) => {
        // app.request has already shown the error alert; put back what was on screen.
        if (isLocaleRejection(error)) this.unavailable.add(locale);

        this.loading = false;
        this.language = previous.language;
        this.showing = previous.showing && !!this.translation;
        this.changed();

        return null;
      });
  }

  protected subjectChanged(): boolean {
    return !!this.fetchedFor && this.subject.freshness > this.fetchedFor;
  }

  refresh(): Promise<T | null> {
    if (!this.language) return Promise.resolve(null);

    return this.translate(this.language, true);
  }

  /**
   * Show a translation fetched elsewhere (the thread's bulk lookup) as if this state had requested it.
   */
  adopt(translation: T) {
    this.translation = translation;
    this.language = translation.language();
    this.fetchedFor = this.subject.freshness;
    this.showing = true;
    this.changed();
  }

  close(redraw = true) {
    if (!this.showing) return;

    this.showing = false;
    this.changed(redraw);
  }

  protected changed(redraw = true) {
    this.version++;

    if (redraw) m.redraw();
  }
}

/**
 * A 422 pointing at the `locale` attribute: this subject cannot be translated
 * into that locale (it is already in it, or it is not offered). Transient
 * failures point at `/data/attributes/translation` instead.
 */
export function isLocaleRejection(error: unknown): boolean {
  const e = error as RequestError | null;

  return (
    !!e &&
    e.status === 422 &&
    !!e.response?.errors?.some((err) => (err.source as { pointer?: string } | undefined)?.pointer === '/data/attributes/locale')
  );
}
