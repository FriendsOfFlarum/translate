/**
 * Primary language subtag, lowercased: `pt-BR` → `pt`, `zh_Hans` → `zh`.
 */
export declare function primaryLanguage(code: string | null | undefined): string | null;
/**
 * The target locales worth offering for content in `sourceLang`: every
 * target whose primary language differs from the source, minus the locales
 * the API already refused for this content. An unknown source keeps every
 * other target.
 */
export declare function filterTargets(targets: readonly string[], sourceLang: string | null | undefined, unavailable?: ReadonlySet<string>): string[];
/**
 * Whether content in `sourceLang` is already in the reader's own language. Such
 * content gets no translate control at all: translating into other forum
 * languages is for content the reader cannot read, not for content they can.
 */
export declare function isReadersLanguage(sourceLang: string | null | undefined): boolean;
/**
 * The locales the current actor may translate into, per the forum payload.
 */
export declare function actorTargets(): string[];
/**
 * Targets on offer for a piece of content in `sourceLang`, for the current actor.
 */
export declare function availableTargets(sourceLang: string | null | undefined, unavailable?: ReadonlySet<string>): string[];
/**
 * Order targets so the reader's own locale comes first.
 */
export declare function sortTargets(targets: string[]): string[];
