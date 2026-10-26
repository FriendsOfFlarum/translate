import Component, { type ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
import type PollTranslation from '../../common/models/PollTranslation';
import type TranslationState from '../states/TranslationState';
export interface IPollTranslationNoticeAttrs extends ComponentAttrs {
    state: TranslationState<PollTranslation>;
    source: string | null;
}
/**
 * Strip above a poll whose texts are swapped for a translation, with the
 * "Show original" toggle.
 */
export default class PollTranslationNotice extends Component<IPollTranslationNoticeAttrs> {
    view(): Mithril.Children;
}
