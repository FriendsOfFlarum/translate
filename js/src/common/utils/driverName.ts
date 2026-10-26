import app from 'flarum/common/app';

/**
 * Display name of a translation driver; third-party drivers without a
 * locale string fall back to their name.
 */
export default function driverName(name: string): string {
  const names: Record<string, () => string> = {
    claude: () => app.translator.trans('fof-translate.lib.drivers.claude', {}, true),
    openai: () => app.translator.trans('fof-translate.lib.drivers.openai', {}, true),
    ollama: () => app.translator.trans('fof-translate.lib.drivers.ollama', {}, true),
    deepl: () => app.translator.trans('fof-translate.lib.drivers.deepl', {}, true),
  };

  return names[name]?.() || name;
}
