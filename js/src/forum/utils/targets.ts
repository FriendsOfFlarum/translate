import app from 'flarum/forum/app';

/**
 * Primary language subtag, lowercased: `pt-BR` → `pt`, `zh_Hans` → `zh`.
 */
export function primaryLanguage(code: string | null | undefined): string | null {
  if (!code) return null;

  return code.split(/[-_]/)[0].toLowerCase() || null;
}

/**
 * The target locales worth offering for content in `sourceLang`: every
 * target whose primary language differs from the source, minus the locales
 * the API already refused for this content. An unknown source keeps every
 * other target.
 */
export function filterTargets(
  targets: readonly string[],
  sourceLang: string | null | undefined,
  unavailable: ReadonlySet<string> = new Set()
): string[] {
  const source = primaryLanguage(sourceLang);

  return targets.filter(
    (code, index) => !!code && targets.indexOf(code) === index && !unavailable.has(code) && (!source || primaryLanguage(code) !== source)
  );
}

/**
 * Whether content in `sourceLang` is already in the reader's own language. Such
 * content gets no translate control at all: translating into other forum
 * languages is for content the reader cannot read, not for content they can.
 */
export function isReadersLanguage(sourceLang: string | null | undefined): boolean {
  const source = primaryLanguage(sourceLang);

  return !!source && source === primaryLanguage(app.translator.getLocale());
}

/**
 * The locales the current actor may translate into, per the forum payload.
 */
export function actorTargets(): string[] {
  if (!app.forum.attribute<boolean>('fof-translate.canTranslate')) return [];

  const targets = app.forum.attribute<string[] | undefined>('fof-translate.targetLanguages');

  return Array.isArray(targets) ? targets : [];
}

/**
 * Targets on offer for a piece of content in `sourceLang`, for the current actor.
 */
export function availableTargets(sourceLang: string | null | undefined, unavailable?: ReadonlySet<string>): string[] {
  return filterTargets(actorTargets(), sourceLang, unavailable);
}

/**
 * Order targets so the reader's own locale comes first.
 */
export function sortTargets(targets: string[]): string[] {
  const locale = app.translator.getLocale();

  return [...targets].sort((a, b) => Number(b === locale) - Number(a === locale));
}
