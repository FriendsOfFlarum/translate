import app from 'flarum/common/app';

/**
 * Human-readable name of a language code, in the reader's locale or (when
 * `useNativeLocaleNames` is on) in the language itself.
 */
export default function LangDisplayName(langCode: string): string {
  if (!langCode || typeof langCode !== 'string') {
    return langCode;
  }

  const locale = app.translator.getLocale();
  const useNative = app.forum.attribute<boolean>('fof-translate.useNativeLocaleNames');

  if (!locale) {
    return langCode;
  }

  const displayLocale = useNative ? langCode : locale;

  try {
    if (typeof Intl === 'undefined' || !Intl.DisplayNames || !Intl.DisplayNames.supportedLocalesOf(displayLocale).length) {
      return langCode;
    }

    const languageNames = new Intl.DisplayNames([displayLocale], { type: 'language' });

    return capitalizeFirstLetter(languageNames.of(langCode) ?? langCode);
  } catch {
    // Intl throws a RangeError on malformed tags.
    return langCode;
  }
}

function capitalizeFirstLetter(word: string): string {
  return word.charAt(0).toUpperCase() + word.slice(1);
}
