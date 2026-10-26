import app from 'flarum/admin/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Alert from 'flarum/common/components/Alert';
import Icon from 'flarum/common/components/Icon';
import type ExtensionPage from 'flarum/admin/components/ExtensionPage';
import type { SettingsComponentOptions } from 'flarum/admin/components/AdminPage';
import type Mithril from 'mithril';
import { AI_DRIVERS, findDriver } from './drivers';
import { driverIntro, driverName, envVar, fieldHelp, fieldLabel, optionLabel } from './driverLabels';
import DeeplGlossarySettings from './DeeplGlossarySettings';
import DriverTest from './DriverTest';
import type { DriverField, DriverInfo } from './types';

export interface IDriverSettingsAttrs extends ComponentAttrs {
  page: ExtensionPage;
}

const inputTypes: Record<string, string> = {
  string: 'text',
  url: 'url',
  number: 'number',
  select: 'select',
  switch: 'switch',
};

/**
 * Fields of the currently selected driver, built from the `fof-translate.drivers` payload.
 */
export default class DriverSettings extends Component<IDriverSettingsAttrs> {
  view(): Mithril.Children {
    const page = this.attrs.page;
    const driver = findDriver(page.setting('fof-translate.driver')());

    if (!driver) return null;

    return (
      <fieldset className="FofTranslateDriverSettings">
        <legend>{app.translator.trans('fof-translate.admin.settings.driver_section_heading', { driver: driverName(driver.name) })}</legend>

        {driverIntro(driver.name) && <p className="helpText FofTranslateDriverSettings-intro">{driverIntro(driver.name)}</p>}

        {!driver.configured && (
          <Alert type="warning" dismissible={false} className="FofTranslateDriverSettings-alert">
            {app.translator.trans('fof-translate.admin.settings.driver_not_configured')}
          </Alert>
        )}

        {Object.keys(driver.fields).map((key) => this.field(driver, key, driver.fields[key]))}

        {AI_DRIVERS.includes(driver.name) && this.aiFields()}

        {driver.name === 'deepl' && <DeeplGlossarySettings page={page} />}

        <DriverTest page={page} driver={driver} />
      </fieldset>
    );
  }

  field(driver: DriverInfo, key: string, field: DriverField): Mithril.Children {
    const page = this.attrs.page;
    const label = fieldLabel(driver.name, key);

    if (field.envOverride) {
      return (
        <div className="Form-group FofTranslateDriverSettings-env">
          <label>{label}</label>
          <p className="helpText">
            <Icon name="fas fa-server" />{' '}
            {key === 'api_key'
              ? app.translator.trans('fof-translate.admin.settings.env_override_named', { env: <code>{envVar(driver.name, key)}</code> })
              : app.translator.trans('fof-translate.admin.settings.env_override')}
          </p>
        </div>
      );
    }

    const entry: Record<string, unknown> = {
      setting: `fof-translate.${driver.name}.${key}`,
      type: inputTypes[field.type] ?? 'text',
      label: field.required ? [label, ' ', <span className="FofTranslateDriverSettings-required">*</span>] : label,
      help: fieldHelp(driver.name, key),
    };

    if (field.type === 'select') {
      entry.options = this.options(driver.name, key, field.options);
    }

    // A masked text input, not type="password": browsers offer to save any password field as
    // a login, prompting when the admin leaves the page. The data-* attributes keep password
    // manager extensions away too.
    if (field.type === 'password') {
      Object.assign(entry, {
        type: 'text',
        className: 'FofTranslateDriverSettings-secret',
        autocomplete: 'off',
        spellcheck: false,
        'data-1p-ignore': true,
        'data-lpignore': 'true',
        'data-bwignore': true,
        'data-form-type': 'other',
      });
    }

    return page.buildSettingComponent(entry as SettingsComponentOptions);
  }

  options(driver: string, key: string, options: DriverField['options']): Record<string, string> {
    if (!options) return {};

    if (Array.isArray(options)) {
      return options.reduce<Record<string, string>>((map, value) => {
        map[value] = optionLabel(driver, key, value);
        return map;
      }, {});
    }

    return Object.keys(options).reduce<Record<string, string>>((map, value) => {
      const translated = optionLabel(driver, key, value);
      map[value] = translated !== value ? translated : options[value];
      return map;
    }, {});
  }

  aiFields(): Mithril.Children {
    const page = this.attrs.page;

    return [
      page.buildSettingComponent({
        setting: 'fof-translate.ai.en_variant',
        type: 'select',
        label: app.translator.trans('fof-translate.admin.settings.ai.en_variant_label'),
        help: app.translator.trans('fof-translate.admin.settings.ai.en_variant_help'),
        options: {
          'en-GB': app.translator.trans('fof-translate.admin.settings.english_options.en-GB'),
          'en-US': app.translator.trans('fof-translate.admin.settings.english_options.en-US'),
        },
      } as SettingsComponentOptions),
      page.buildSettingComponent({
        setting: 'fof-translate.ai.de_variant',
        type: 'select',
        label: app.translator.trans('fof-translate.admin.settings.ai.de_variant_label'),
        help: app.translator.trans('fof-translate.admin.settings.ai.de_variant_help'),
        options: {
          casual: app.translator.trans('fof-translate.admin.settings.ai.de_variant_options.casual'),
          swiss: app.translator.trans('fof-translate.admin.settings.ai.de_variant_options.swiss'),
        },
      } as SettingsComponentOptions),
    ];
  }
}
