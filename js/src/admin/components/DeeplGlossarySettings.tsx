import app from 'flarum/admin/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type ExtensionPage from 'flarum/admin/components/ExtensionPage';
import type { SettingsComponentOptions } from 'flarum/admin/components/AdminPage';
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
 * Keyed by the settings page instance, so the glossaries are fetched once per
 * visit and survive the component remounting when the driver select changes.
 */
const loads = new WeakMap<ExtensionPage, GlossaryLoad>();

/**
 * One glossary select per language pair, loaded from the DeepL account on first display.
 */
export default class DeeplGlossarySettings extends Component<IDeeplGlossarySettingsAttrs> {
  oninit(vnode: Mithril.Vnode<IDeeplGlossarySettingsAttrs, this>) {
    super.oninit(vnode);

    if (!loads.has(this.attrs.page)) this.load();
  }

  glossaryLoad(): GlossaryLoad {
    return loads.get(this.attrs.page) ?? { loading: false, failed: false, pairs: null };
  }

  load() {
    if (this.glossaryLoad().loading) return;

    const state: GlossaryLoad = { ...this.glossaryLoad(), loading: true, failed: false };

    loads.set(this.attrs.page, state);

    app
      .request<{ data: GlossaryPairs }>({
        method: 'GET',
        url: `${app.forum.attribute<string>('apiUrl')}/fof-translate/deepl/glossaries`,
      })
      .then((response) => {
        state.pairs = response?.data && typeof response.data === 'object' ? response.data : {};
      })
      .catch(() => {
        state.failed = true;
      })
      .finally(() => {
        state.loading = false;
        m.redraw();
      });
  }

  view(): Mithril.Children {
    return (
      <div className="Form-group FofTranslateDeeplGlossaries">
        <label>{app.translator.trans('fof-translate.admin.settings.deepl.glossaries_heading')}</label>
        <p className="helpText">
          {app.translator.trans('fof-translate.admin.settings.deepl.glossaries_help')}{' '}
          {!this.glossaryLoad().loading && !this.glossaryLoad().failed && (
            <Button className="Button Button--link FofTranslateDeeplGlossaries-reload" icon="fas fa-sync" onclick={() => this.load()}>
              {app.translator.trans('fof-translate.admin.settings.deepl.glossaries_reload_button')}
            </Button>
          )}
        </p>
        {this.content()}
      </div>
    );
  }

  content(): Mithril.Children {
    const { loading, failed, pairs: loaded } = this.glossaryLoad();

    if (loading) return <LoadingIndicator display="block" size="small" />;

    if (failed) {
      return (
        <p className="helpText">
          {app.translator.trans('fof-translate.admin.settings.deepl.glossaries_failed')}{' '}
          <Button className="Button Button--link" icon="fas fa-sync" onclick={() => this.load()}>
            {app.translator.trans('fof-translate.admin.settings.deepl.glossaries_retry_button')}
          </Button>
        </p>
      );
    }

    const pairs = Object.keys(loaded ?? {}).sort();

    if (!pairs.length) {
      return <p className="helpText">{app.translator.trans('fof-translate.admin.settings.deepl.glossaries_empty')}</p>;
    }

    return pairs.map((pair) => {
      const [source, target] = pair.split('_to_');

      return this.attrs.page.buildSettingComponent({
        setting: `fof-translate.deepl.glossary_${pair}`,
        type: 'select',
        label: app.translator.trans('fof-translate.admin.settings.deepl.glossary_pair_label', {
          source: (source ?? pair).toUpperCase(),
          target: (target ?? '').toUpperCase(),
        }),
        options: {
          none: app.translator.trans('fof-translate.admin.settings.deepl.glossary_none_option'),
          ...loaded![pair],
        },
      } as SettingsComponentOptions);
    });
  }
}
