import Component, { type ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
import type TranslationState from '../states/TranslationState';
export interface ITranslateButtonAttrs extends ComponentAttrs {
    state: TranslationState;
    /** Locales the actor may translate into. */
    targets: string[];
    /** Detected language of the content; `null` when unknown. */
    source: string | null;
    buttonClassName?: string;
    className?: string;
    /** Replaces the default action (translating `state`'s subject), e.g. to translate a whole thread. */
    onTranslate?: (locale: string) => unknown;
}
/**
 * "Translate" action: a button for a single target locale, a dropdown for several.
 */
export default class TranslateButton extends Component<ITranslateButtonAttrs> {
    static targetsFor(targets: string[], source: string | null, unavailable?: ReadonlySet<string>): string[];
    view(): Mithril.Children;
    translate(locale: string): unknown;
}
