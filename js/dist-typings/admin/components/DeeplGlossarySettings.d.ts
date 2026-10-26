import Component, { type ComponentAttrs } from 'flarum/common/Component';
import type ExtensionPage from 'flarum/admin/components/ExtensionPage';
import type Mithril from 'mithril';
export interface IDeeplGlossarySettingsAttrs extends ComponentAttrs {
    page: ExtensionPage;
}
/** `{ en_to_de: { [glossaryId]: label } }` */
type GlossaryPairs = Record<string, Record<string, string>>;
interface GlossaryLoad {
    loading: boolean;
    failed: boolean;
    pairs: GlossaryPairs | null;
}
/**
 * One glossary select per language pair, loaded from the DeepL account on first display.
 */
export default class DeeplGlossarySettings extends Component<IDeeplGlossarySettingsAttrs> {
    oninit(vnode: Mithril.Vnode<IDeeplGlossarySettingsAttrs, this>): void;
    glossaryLoad(): GlossaryLoad;
    load(): void;
    view(): Mithril.Children;
    content(): Mithril.Children;
}
export {};
