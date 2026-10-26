import app from 'flarum/admin/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import extractText from 'flarum/common/utils/extractText';
import type ExtensionPage from 'flarum/admin/components/ExtensionPage';
import type Mithril from 'mithril';

export interface IGlossaryEditorAttrs extends ComponentAttrs {
  page: ExtensionPage;
}

/**
 * Terms that are never translated, stored as a JSON array in `fof-translate.glossary`.
 */
export default class GlossaryEditor extends Component<IGlossaryEditorAttrs> {
  draft = '';

  terms(): string[] {
    const raw = this.attrs.page.setting('fof-translate.glossary')();

    if (!raw) return [];

    try {
      const parsed = JSON.parse(raw);

      return Array.isArray(parsed) ? parsed.filter((term): term is string => typeof term === 'string') : [];
    } catch {
      return [];
    }
  }

  save(terms: string[]) {
    this.attrs.page.setting('fof-translate.glossary')(JSON.stringify(terms));
  }

  add() {
    const term = this.draft.trim();
    const terms = this.terms();

    if (term && !terms.includes(term)) {
      this.save([...terms, term]);
    }

    this.draft = '';
  }

  remove(index: number) {
    const terms = this.terms();

    terms.splice(index, 1);
    this.save(terms);
  }

  view(): Mithril.Children {
    const terms = this.terms();
    const inputId = 'fof-translate-glossary-input';

    return (
      <div className="Form-group FofTranslateGlossary">
        <label for={inputId}>{app.translator.trans('fof-translate.admin.settings.glossary.label')}</label>
        <p className="helpText">{app.translator.trans('fof-translate.admin.settings.glossary.help')}</p>

        {terms.length ? (
          <ul className="FofTranslateGlossary-terms">
            {terms.map((term, index) => (
              <li className="FofTranslateGlossary-term" key={term}>
                <span className="FofTranslateGlossary-termText">{term}</span>
                <Button
                  className="Button Button--icon Button--link FofTranslateGlossary-remove"
                  icon="fas fa-times"
                  aria-label={extractText(app.translator.trans('fof-translate.admin.settings.glossary.remove_button', { term }))}
                  onclick={() => this.remove(index)}
                />
              </li>
            ))}
          </ul>
        ) : (
          <p className="helpText FofTranslateGlossary-empty">{app.translator.trans('fof-translate.admin.settings.glossary.empty_text')}</p>
        )}

        <div className="FofTranslateGlossary-add">
          <input
            id={inputId}
            className="FormControl"
            value={this.draft}
            placeholder={extractText(app.translator.trans('fof-translate.admin.settings.glossary.placeholder'))}
            oninput={(e: InputEvent) => (this.draft = (e.target as HTMLInputElement).value)}
            onkeydown={(e: KeyboardEvent) => {
              if (e.key === 'Enter') {
                // Enter would submit the settings form.
                e.preventDefault();
                this.add();
              }
            }}
          />
          <Button className="Button" icon="fas fa-plus" disabled={!this.draft.trim()} onclick={() => this.add()}>
            {app.translator.trans('fof-translate.admin.settings.glossary.add_button')}
          </Button>
        </div>
      </div>
    );
  }
}
