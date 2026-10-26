import app from 'flarum/admin/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Alert from 'flarum/common/components/Alert';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import type ExtensionPage from 'flarum/admin/components/ExtensionPage';
import type Mithril from 'mithril';
import LangDisplayName from '../../common/utils/LangDisplayName';
import type { DriverInfo } from './types';

export interface DriverTestResult {
  ok: boolean;
  driver: string;
  durationMs: number;
  source: { language: string; text: string };
  target: { language: string };
  translation: { text: string; html: string } | null;
  detectedSourceLanguage: string | null;
  markupPreserved: boolean | null;
  error: string | null;
}

interface DriverTestState {
  /** Driver the result belongs to; a different selection clears it. */
  driver: string | null;
  loading: boolean;
  result: DriverTestResult | null;
}

const states = new WeakMap<ExtensionPage, DriverTestState>();

export interface IDriverTestAttrs extends ComponentAttrs {
  page: ExtensionPage;
  driver: DriverInfo;
}

/**
 * "Test translation" for the selected driver, using the unsaved form values.
 */
export default class DriverTest extends Component<IDriverTestAttrs> {
  testState(): DriverTestState {
    const { page, driver } = this.attrs;
    let state = states.get(page);

    if (!state || state.driver !== driver.name) {
      state = { driver: driver.name, loading: false, result: null };
      states.set(page, state);
    }

    return state;
  }

  /**
   * Current form values for this driver and the shared AI settings, minus
   * fields the environment overrides.
   */
  formSettings(): Record<string, unknown> {
    const { page, driver } = this.attrs;
    const prefix = `fof-translate.${driver.name}.`;
    const settings: Record<string, unknown> = {};
    const envKeys = Object.keys(driver.fields)
      .filter((key) => driver.fields[key].envOverride)
      .map((key) => prefix + key);

    const keys = new Set([
      ...Object.keys(driver.fields).map((key) => prefix + key),
      ...Object.keys(page.settings).filter((key) => key.startsWith(prefix) || key.startsWith('fof-translate.ai.')),
    ]);

    keys.forEach((key) => {
      if (!envKeys.includes(key)) settings[key] = page.setting(key)();
    });

    return settings;
  }

  run() {
    const state = this.testState();
    const driver = this.attrs.driver.name;

    if (state.loading) return;

    state.loading = true;
    state.result = null;

    app
      .request<{ data: DriverTestResult }>({
        method: 'POST',
        url: `${app.forum.attribute<string>('apiUrl')}/fof-translate/drivers/${encodeURIComponent(driver)}/test`,
        body: { settings: this.formSettings() },
      })
      .then((response) => {
        // Ignore a late answer for a driver that is no longer selected.
        if (state.driver === driver) state.result = response.data;
      })
      .catch(() => {})
      .finally(() => {
        state.loading = false;
        m.redraw();
      });
  }

  view(): Mithril.Children {
    const state = this.testState();

    return (
      <div className="Form-group FofTranslateDriverTest">
        <Button className="Button" icon="fas fa-vial" loading={state.loading} disabled={state.loading} onclick={() => this.run()}>
          {app.translator.trans('fof-translate.admin.test.button')}
        </Button>
        <p className="helpText">{app.translator.trans('fof-translate.admin.test.help')}</p>
        {state.result && this.resultView(state.result)}
      </div>
    );
  }

  resultView(result: DriverTestResult): Mithril.Children {
    if (!result.ok || !result.translation) {
      return (
        <Alert type="error" dismissible={false} className="FofTranslateDriverTest-result">
          {result.error
            ? app.translator.trans('fof-translate.admin.test.failed_text', { error: result.error })
            : app.translator.trans('fof-translate.admin.test.failed_unknown_text')}
        </Alert>
      );
    }

    return (
      <div className="FofTranslateDriverTest-result FofTranslateDriverTest-result--ok">
        <p className="FofTranslateDriverTest-title">
          <Icon name="fas fa-check-circle" />{' '}
          {app.translator.trans('fof-translate.admin.test.success_text', {
            source: LangDisplayName(result.source.language),
            target: LangDisplayName(result.target.language),
            seconds: (result.durationMs / 1000).toFixed(1),
          })}
        </p>

        <div className="FofTranslateDriverTest-section">
          <div className="FofTranslateDriverTest-label">{app.translator.trans('fof-translate.admin.test.source_label')}</div>
          <div className="FofTranslateDriverTest-source" lang={result.source.language}>
            {result.source.text}
          </div>
        </div>

        <div className="FofTranslateDriverTest-section">
          <div className="FofTranslateDriverTest-label">{app.translator.trans('fof-translate.admin.test.translation_label')}</div>
          <div className="FofTranslateDriverTest-translation" lang={result.target.language}>
            {m.trust(result.translation.html)}
          </div>
        </div>

        {result.detectedSourceLanguage && (
          <p className="helpText">
            {app.translator.trans('fof-translate.admin.test.detected_text', { language: LangDisplayName(result.detectedSourceLanguage) })}
          </p>
        )}

        {result.markupPreserved === false && (
          <p className="FofTranslateDriverTest-warning">
            <Icon name="fas fa-exclamation-triangle" /> {app.translator.trans('fof-translate.admin.test.markup_warning')}
          </p>
        )}
      </div>
    );
  }
}
