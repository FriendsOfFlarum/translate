import app from 'flarum/admin/app';
import type Mithril from 'mithril';
import driverName from '../../common/utils/driverName';

export { driverName };

type FieldText = { label: () => Mithril.Children; help?: () => Mithril.Children };

const fieldTexts: Record<string, FieldText> = {
  'claude.api_key': { label: () => app.translator.trans('fof-translate.admin.settings.claude.api_key_label') },
  'claude.model': {
    label: () => app.translator.trans('fof-translate.admin.settings.claude.model_label'),
    help: () => app.translator.trans('fof-translate.admin.settings.claude.model_help'),
  },
  'claude.effort': {
    label: () => app.translator.trans('fof-translate.admin.settings.claude.effort_label'),
    help: () => app.translator.trans('fof-translate.admin.settings.claude.effort_help'),
  },
  'claude.timeout': {
    label: () => app.translator.trans('fof-translate.admin.settings.timeout_label'),
    help: () => app.translator.trans('fof-translate.admin.settings.timeout_help'),
  },
  'openai.api_key': { label: () => app.translator.trans('fof-translate.admin.settings.openai.api_key_label') },
  'openai.model': {
    label: () => app.translator.trans('fof-translate.admin.settings.openai.model_label'),
    help: () => app.translator.trans('fof-translate.admin.settings.openai.model_help'),
  },
  'openai.max_tokens': {
    label: () => app.translator.trans('fof-translate.admin.settings.openai.max_tokens_label'),
    help: () => app.translator.trans('fof-translate.admin.settings.openai.max_tokens_help'),
  },
  'openai.timeout': {
    label: () => app.translator.trans('fof-translate.admin.settings.timeout_label'),
    help: () => app.translator.trans('fof-translate.admin.settings.timeout_help'),
  },
  'ollama.base_url': {
    label: () => app.translator.trans('fof-translate.admin.settings.ollama.base_url_label'),
    help: () => app.translator.trans('fof-translate.admin.settings.ollama.base_url_help'),
  },
  'ollama.model': {
    label: () => app.translator.trans('fof-translate.admin.settings.ollama.model_label'),
    help: () => app.translator.trans('fof-translate.admin.settings.ollama.model_help'),
  },
  'ollama.api_key': {
    label: () => app.translator.trans('fof-translate.admin.settings.ollama.api_key_label'),
    help: () => app.translator.trans('fof-translate.admin.settings.ollama.api_key_help', { env: envVar('ollama', 'api_key') }),
  },
  'ollama.think': {
    label: () => app.translator.trans('fof-translate.admin.settings.ollama.think_label'),
    help: () => app.translator.trans('fof-translate.admin.settings.ollama.think_help'),
  },
  'ollama.timeout': {
    label: () => app.translator.trans('fof-translate.admin.settings.timeout_label'),
    help: () => app.translator.trans('fof-translate.admin.settings.timeout_help'),
  },
  'deepl.api_key': {
    label: () => app.translator.trans('fof-translate.admin.settings.deepl.api_key_label'),
  },
  'deepl.formality': {
    label: () => app.translator.trans('fof-translate.admin.settings.deepl.formality_label'),
    help: () => app.translator.trans('fof-translate.admin.settings.deepl.formality_help'),
  },
  'deepl.en_choice': {
    label: () => app.translator.trans('fof-translate.admin.settings.deepl.en_choice_label'),
    help: () => app.translator.trans('fof-translate.admin.settings.deepl.en_choice_help'),
  },
  'deepl.pt_choice': {
    label: () => app.translator.trans('fof-translate.admin.settings.deepl.pt_choice_label'),
    help: () => app.translator.trans('fof-translate.admin.settings.deepl.pt_choice_help'),
  },
  'deepl.model_type': {
    label: () => app.translator.trans('fof-translate.admin.settings.deepl.model_type_label'),
    help: () => app.translator.trans('fof-translate.admin.settings.deepl.model_type_help'),
  },
};

const optionTexts: Record<string, () => string> = {
  'claude.effort.low': () => app.translator.trans('fof-translate.admin.settings.claude.effort_options.low', {}, true),
  'claude.effort.medium': () => app.translator.trans('fof-translate.admin.settings.claude.effort_options.medium', {}, true),
  'claude.effort.high': () => app.translator.trans('fof-translate.admin.settings.claude.effort_options.high', {}, true),
  'deepl.formality.default': () => app.translator.trans('fof-translate.admin.settings.deepl.formality_options.default', {}, true),
  'deepl.formality.prefer_more': () => app.translator.trans('fof-translate.admin.settings.deepl.formality_options.prefer_more', {}, true),
  'deepl.formality.prefer_less': () => app.translator.trans('fof-translate.admin.settings.deepl.formality_options.prefer_less', {}, true),
  'deepl.en_choice.en-US': () => app.translator.trans('fof-translate.admin.settings.english_options.en-US', {}, true),
  'deepl.en_choice.en-GB': () => app.translator.trans('fof-translate.admin.settings.english_options.en-GB', {}, true),
  'deepl.pt_choice.pt-PT': () => app.translator.trans('fof-translate.admin.settings.portuguese_options.pt-PT', {}, true),
  'deepl.pt_choice.pt-BR': () => app.translator.trans('fof-translate.admin.settings.portuguese_options.pt-BR', {}, true),
  'ollama.think.off': () => app.translator.trans('fof-translate.admin.settings.ollama.think_options.off', {}, true),
  'ollama.think.low': () => app.translator.trans('fof-translate.admin.settings.ollama.think_options.low', {}, true),
  'ollama.think.medium': () => app.translator.trans('fof-translate.admin.settings.ollama.think_options.medium', {}, true),
  'ollama.think.high': () => app.translator.trans('fof-translate.admin.settings.ollama.think_options.high', {}, true),
  'deepl.model_type.latency_optimized': () =>
    app.translator.trans('fof-translate.admin.settings.deepl.model_type_options.latency_optimized', {}, true),
  'deepl.model_type.prefer_quality_optimized': () =>
    app.translator.trans('fof-translate.admin.settings.deepl.model_type_options.prefer_quality_optimized', {}, true),
};

export function fieldLabel(driver: string, key: string): Mithril.Children {
  return fieldTexts[`${driver}.${key}`]?.label() ?? key;
}

/**
 * Help text for a driver field; API key fields name the environment variable that overrides them.
 */
export function fieldHelp(driver: string, key: string): Mithril.Children {
  const help = fieldTexts[`${driver}.${key}`]?.help?.();

  if (help) return help;

  if (key === 'api_key') {
    return app.translator.trans('fof-translate.admin.settings.api_key_help', { env: envVar(driver, key) });
  }

  return null;
}

export function optionLabel(driver: string, key: string, value: string): string {
  return optionTexts[`${driver}.${key}.${value}`]?.() || value;
}

export function envVar(driver: string, key: string): string {
  return `FOF_TRANSLATE_${driver}_${key}`.toUpperCase().replace(/[^A-Z0-9_]/g, '_');
}
