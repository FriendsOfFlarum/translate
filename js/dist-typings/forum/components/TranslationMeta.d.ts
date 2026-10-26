import Component, { type ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
export interface ITranslationMetaAttrs extends ComponentAttrs {
    provider: string | null;
    updatedAt: Date | null;
}
/**
 * "Claude · 3 minutes ago" line under a translation.
 */
export default class TranslationMeta extends Component<ITranslationMetaAttrs> {
    view(): Mithril.Children;
}
