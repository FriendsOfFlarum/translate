import Component, { type ComponentAttrs } from 'flarum/common/Component';
import type ExtensionPage from 'flarum/admin/components/ExtensionPage';
import type Mithril from 'mithril';
export type DetectionType = 'posts' | 'discussions';
type Counts = Record<DetectionType, {
    total: number;
    pending: number;
    undetermined: number;
}>;
interface BatchResult {
    processed: number;
    detected: number;
    after: number | null;
    done: boolean;
}
interface Progress {
    type: DetectionType;
    processed: number;
    expected: number;
}
type Totals = Record<DetectionType, {
    processed: number;
    detected: number;
}>;
/**
 * Counts and backfill-run state, kept per settings page visit so it survives
 * redraws and remounts, and counts load only once per visit.
 */
export declare class DetectionState {
    counts: Counts | null;
    loadingCounts: boolean;
    running: boolean;
    cancelled: boolean;
    force: boolean;
    progress: Progress | null;
    totals: Totals | null;
    /** `done`, `cancelled` or `failed` once a run has ended. */
    outcome: 'done' | 'cancelled' | 'failed' | null;
    url(): string;
    loadCounts(): Promise<void>;
    run(force: boolean): Promise<void>;
    batch(type: DetectionType, force: boolean, after: number | null): Promise<BatchResult>;
    cancel(): void;
}
export interface ILanguageDetectionAttrs extends ComponentAttrs {
    page: ExtensionPage;
}
/**
 * Backfills `detected_lang` for content written before the extension was
 * installed, batch by batch, so the translate button can hide itself on
 * content already in the reader's language.
 */
export default class LanguageDetection extends Component<ILanguageDetectionAttrs> {
    detection: DetectionState;
    oninit(vnode: Mithril.Vnode<ILanguageDetectionAttrs, this>): void;
    view(): Mithril.Children;
    redetectAll(): void;
    countsView(): Mithril.Children;
    /**
     * `total` stays a number for the plural; the rest are formatted like the plural's `#`.
     */
    statusParams(count: Counts[DetectionType]): Record<string, number | string>;
    progressView(): Mithril.Children;
    summaryView(): Mithril.Children;
}
export {};
