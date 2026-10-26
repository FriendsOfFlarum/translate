import app from 'flarum/forum/app';
import { bootForum } from './fixtures';
import { availableTargets, filterTargets, isReadersLanguage, primaryLanguage } from '../../../src/forum/utils/targets';
import TranslateButton from '../../../src/forum/components/TranslateButton';

describe('translate target filtering', () => {
  beforeAll(() => bootForum());

  test('primaryLanguage reduces a locale to its language subtag', () => {
    expect(primaryLanguage('pt-BR')).toBe('pt');
    expect(primaryLanguage('zh_Hans')).toBe('zh');
    expect(primaryLanguage(null)).toBeNull();
  });

  test('an unknown source keeps every target', () => {
    expect(filterTargets(['en', 'de'], null)).toEqual(['en', 'de']);
  });

  test('the source language is excluded, regional variants included', () => {
    expect(filterTargets(['en', 'de', 'pt-BR'], 'de')).toEqual(['en', 'pt-BR']);
    expect(filterTargets(['en', 'de', 'pt-BR'], 'pt')).toEqual(['en', 'de']);
  });

  test('duplicates and empty codes are dropped', () => {
    expect(filterTargets(['en', '', 'en', 'de'], 'zh')).toEqual(['en', 'de']);
  });

  test('content already in the only target offers nothing', () => {
    expect(filterTargets(['en'], 'en')).toEqual([]);
  });

  test('locales the API refused are excluded', () => {
    expect(filterTargets(['en', 'de', 'fr'], null, new Set(['en']))).toEqual(['de', 'fr']);
    expect(TranslateButton.targetsFor(['en', 'de'], 'fr', new Set(['en', 'de']))).toEqual([]);
  });

  test('content in the reader’s own language is recognised, regional variants included', () => {
    const locale = app.translator.getLocale();

    expect(isReadersLanguage(locale)).toBe(true);
    expect(isReadersLanguage(`${locale}-XX`)).toBe(true);
    expect(isReadersLanguage(locale === 'zh' ? 'fr' : 'zh')).toBe(false);
    expect(isReadersLanguage(null)).toBe(false);
  });

  test('availableTargets reads the forum payload', () => {
    expect(availableTargets('fr')).toEqual(['en', 'de']);
  });

  test('targetsFor puts the reader locale first', () => {
    expect(app.translator.getLocale()).toBe('en');
    expect(TranslateButton.targetsFor(['fr', 'de', 'en'], 'zh')).toEqual(['en', 'fr', 'de']);
  });

  test('an actor who may not translate gets no targets', () => {
    app.forum.pushAttributes({ 'fof-translate.canTranslate': false });

    expect(availableTargets(null)).toEqual([]);

    app.forum.pushAttributes({ 'fof-translate.canTranslate': true });
  });
});
