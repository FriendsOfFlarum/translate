import Component, { type ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
import type TranslationRecord from '../../common/models/TranslationRecord';
import type TranslationState from '../states/TranslationState';
export interface ITranslationStatusAttrs extends ComponentAttrs {
    state: TranslationState;
    /** Detected language of the original, used when the provider reported none. */
    source: string | null;
    className?: string;
    /** Replaces the default "Show original" (closing `state`), e.g. to close a whole thread. */
    onClose?: () => void;
}
/**
 * One-line status under content whose text has been swapped for a translation
 * in place: "Translated from German · Show original · ↻". Colours inherit from
 * the surrounding element, so it reads on tag-coloured heroes too.
 */
export default class TranslationStatus extends Component<ITranslationStatusAttrs> {
    view(): Mithril.Children;
    meta(translation: TranslationRecord): string;
    refreshButton(): Mithril.Children;
}
