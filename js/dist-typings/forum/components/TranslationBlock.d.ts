import Component, { type ComponentAttrs } from 'flarum/common/Component';
import ItemList from 'flarum/common/utils/ItemList';
import type TranslationRecord from '../../common/models/TranslationRecord';
import type Mithril from 'mithril';
import type TranslationState from '../states/TranslationState';
export interface ITranslationBlockAttrs<T extends TranslationRecord = TranslationRecord> extends ComponentAttrs {
    state: TranslationState<T>;
    /** Detected language of the original, used when the provider reported none. */
    source: string | null;
    /** Renders the translated body from the active translation. */
    body: (translation: T) => Mithril.Children;
    className?: string;
}
/**
 * Translated content under the original: header with close/refresh, body, meta footer.
 */
export default class TranslationBlock<T extends TranslationRecord = TranslationRecord> extends Component<ITranslationBlockAttrs<T>> {
    view(): Mithril.Children;
    title(translation: T | null): Mithril.Children;
    controlItems(): ItemList<Mithril.Children>;
}
