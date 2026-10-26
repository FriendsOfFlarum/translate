import Component, { type ComponentAttrs } from 'flarum/common/Component';
import type ExtensionPage from 'flarum/admin/components/ExtensionPage';
import type Mithril from 'mithril';
export interface IGlossaryEditorAttrs extends ComponentAttrs {
    page: ExtensionPage;
}
/**
 * Terms that are never translated, stored as a JSON array in `fof-translate.glossary`.
 */
export default class GlossaryEditor extends Component<IGlossaryEditorAttrs> {
    draft: string;
    terms(): string[];
    save(terms: string[]): void;
    add(): void;
    remove(index: number): void;
    view(): Mithril.Children;
}
