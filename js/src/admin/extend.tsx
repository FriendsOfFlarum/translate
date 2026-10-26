import app from 'flarum/admin/app';
import Extend from 'flarum/common/extenders';
import type ExtensionPage from 'flarum/admin/components/ExtensionPage';
import commonExtend from '../common/extend';
import DriverNotice from './components/DriverNotice';
import DriverSettings from './components/DriverSettings';
import GlossaryEditor from './components/GlossaryEditor';
import LanguageDetection from './components/LanguageDetection';
import { driverName } from './components/driverLabels';
import { drivers, RECOMMENDED_DRIVER } from './components/drivers';

function driverOptions(): Record<string, string> {
  const options: Record<string, string> = {
    '': app.translator.trans('fof-translate.admin.settings.driver_none_option', {}, true),
  };

  drivers().forEach((driver) => {
    let label = driverName(driver.name);

    if (driver.name === RECOMMENDED_DRIVER) {
      label = app.translator.trans('fof-translate.admin.settings.driver_recommended_option', { driver: label }, true);
    } else if (driver.name === 'ollama') {
      label = app.translator.trans('fof-translate.admin.settings.driver_self_hosted_option', { driver: label }, true);
    } else if (driver.name === 'google') {
      label = app.translator.trans('fof-translate.admin.settings.driver_unofficial_option', { driver: label }, true);
    }

    options[driver.name] = label;
  });

  return options;
}

export default [
  ...commonExtend,

  new Extend.Admin()
    .customSetting(() => <DriverNotice />, 1000)
    .setting(
      () => ({
        setting: 'fof-translate.driver',
        type: 'select',
        label: app.translator.trans('fof-translate.admin.settings.driver_label'),
        help: app.translator.trans('fof-translate.admin.settings.driver_help'),
        options: driverOptions(),
      }),
      100
    )
    .customSetting(function (this: ExtensionPage) {
      return <DriverSettings page={this} />;
    }, 90)
    .setting(
      () => ({
        setting: 'fof-translate.translate-all-enabled',
        type: 'switch',
        label: app.translator.trans('fof-translate.admin.settings.translate_all_enabled_label'),
        help: app.translator.trans('fof-translate.admin.settings.translate_all_enabled_help'),
      }),
      80
    )
    .setting(
      () => ({
        setting: 'fof-translate.use-native-locale-names',
        type: 'switch',
        label: app.translator.trans('fof-translate.admin.settings.use_native_locale_names_label'),
        help: app.translator.trans('fof-translate.admin.settings.use_native_locale_names_help'),
      }),
      70
    )
    .setting(
      () => ({
        setting: 'fof-translate.short_content_length',
        type: 'number',
        min: 0,
        label: app.translator.trans('fof-translate.admin.settings.short_content_length_label'),
        help: app.translator.trans('fof-translate.admin.settings.short_content_length_help'),
      }),
      60
    )
    .setting(
      () => ({
        setting: 'fof-translate.throttle_per_minute',
        type: 'number',
        min: 0,
        label: app.translator.trans('fof-translate.admin.settings.throttle_per_minute_label'),
        help: app.translator.trans('fof-translate.admin.settings.throttle_per_minute_help'),
      }),
      50
    )
    .customSetting(function (this: ExtensionPage) {
      return <GlossaryEditor page={this} />;
    }, 40)
    .customSetting(function (this: ExtensionPage) {
      return <LanguageDetection page={this} />;
    }, 30)
    .permission(
      () => ({
        icon: 'fas fa-language',
        label: app.translator.trans('fof-translate.admin.permissions.translate_label'),
        permission: 'fof-translate.translate',
        allowGuest: true,
      }),
      'view'
    )
    .permission(
      () => ({
        icon: 'fas fa-globe',
        label: app.translator.trans('fof-translate.admin.permissions.translate_any_language_label'),
        permission: 'fof-translate.translateAnyLanguage',
        allowGuest: true,
      }),
      'reply'
    )
    .permission(
      () => ({
        icon: 'fas fa-sync',
        label: app.translator.trans('fof-translate.admin.permissions.refresh_label'),
        permission: 'fof-translate.refresh',
      }),
      'moderate'
    ),
] satisfies object[];
