import { jest } from '@jest/globals';
import app from 'flarum/forum/app';
import type Post from 'flarum/common/models/Post';
import { bootForum } from './fixtures';
import PostTranslation from '../../../src/common/models/PostTranslation';
import TranslationState, { isLocaleRejection } from '../../../src/forum/states/TranslationState';
import TranslateButton from '../../../src/forum/components/TranslateButton';

function makePost(id: string): Post {
  return app.store.pushObject<Post>({ type: 'posts', id, attributes: { contentType: 'comment', contentHtml: '<p>Hallo</p>' } })!;
}

function rejection(pointer: string) {
  return { status: 422, response: { errors: [{ status: '422', code: 'validation_error', source: { pointer } }] } };
}

function translationPayload(postId: string, language: string) {
  return {
    data: {
      type: 'post-translations',
      id: `${postId}-${language}`,
      attributes: {
        language,
        contentHtml: '<p>Hello</p>',
        provider: 'claude',
        sourceLanguage: 'de',
        updatedAt: '2026-10-25T10:00:00+00:00',
        isStale: false,
        isEdited: false,
      },
    },
  };
}

describe('TranslationState', () => {
  beforeAll(() => {
    bootForum();
    app.store.models['post-translations'] = PostTranslation;
  });

  afterEach(() => jest.restoreAllMocks());

  test('one state per model, shared across lookups (survives remount)', () => {
    const post = makePost('1');
    const state = TranslationState.for(post, 'posts');

    state.showing = true;

    expect(TranslationState.for(post, 'posts')).toBe(state);
    expect(TranslationState.peek(post)?.showing).toBe(true);
    expect(TranslationState.peek(makePost('2'))).toBeUndefined();
  });

  test('translate posts to the subject endpoint and stores the record', async () => {
    const post = makePost('3');
    const request = jest.spyOn(app, 'request').mockResolvedValue(translationPayload('3', 'en') as never);
    const state = TranslationState.for<PostTranslation>(post, 'posts');
    const versionBefore = state.version;

    const translation = await state.translate('en');

    expect(request).toHaveBeenCalledTimes(1);
    expect(request.mock.calls[0][0]).toMatchObject({
      method: 'POST',
      url: 'http://localhost/api/posts/3/translate',
      body: { data: { attributes: { locale: 'en' } } },
    });
    expect(translation).toBeInstanceOf(PostTranslation);
    expect(app.store.getById('post-translations', '3-en')).toBe(translation);
    expect(state.active()?.contentHtml()).toBe('<p>Hello</p>');
    expect(state.loading).toBe(false);
    expect(state.version).toBeGreaterThan(versionBefore);
  });

  test('reopening the same locale makes no request; refresh does', async () => {
    const post = makePost('4');
    const request = jest.spyOn(app, 'request').mockResolvedValue(translationPayload('4', 'en') as never);
    const state = TranslationState.for<PostTranslation>(post, 'posts');

    await state.translate('en');
    state.close();
    expect(state.active()).toBeNull();

    await TranslationState.for<PostTranslation>(post, 'posts').translate('en');
    expect(request).toHaveBeenCalledTimes(1);
    expect(state.active()).not.toBeNull();

    await state.refresh();
    expect(request).toHaveBeenCalledTimes(2);
    expect(request.mock.calls[1][0]).toMatchObject({ body: { data: { attributes: { locale: 'en', refresh: true } } } });
  });

  test('a failed request resets loading and restores the previous view', async () => {
    const post = makePost('5');
    const state = TranslationState.for<PostTranslation>(post, 'posts');

    jest.spyOn(app, 'request').mockResolvedValueOnce(translationPayload('5', 'en') as never);
    await state.translate('en');

    jest.spyOn(app, 'request').mockRejectedValueOnce(new Error('422') as never);
    const result = await state.translate('fr');

    expect(result).toBeNull();
    expect(state.loading).toBe(false);
    expect(state.language).toBe('en');
    expect(state.active()?.language()).toBe('en');
  });

  test('a 422 on the locale marks it unavailable and drops it from the menu', async () => {
    const post = makePost('6');
    const state = TranslationState.for<PostTranslation>(post, 'posts');

    jest.spyOn(app, 'request').mockRejectedValueOnce(rejection('/data/attributes/locale') as never);
    await state.translate('en');

    expect(state.unavailable.has('en')).toBe(true);
    expect(TranslationState.unavailableFor(post).has('en')).toBe(true);
    expect(state.showing).toBe(false);
    expect(state.loading).toBe(false);
    expect(TranslateButton.targetsFor(['en', 'de', 'fr'], null, TranslationState.unavailableFor(post))).toEqual(['de', 'fr']);
  });

  test('a transient failure does not mark the locale unavailable', async () => {
    const post = makePost('7');
    const state = TranslationState.for<PostTranslation>(post, 'posts');

    jest.spyOn(app, 'request').mockRejectedValueOnce(rejection('/data/attributes/translation') as never);
    await state.translate('en');

    expect(state.unavailable.size).toBe(0);
    expect(isLocaleRejection({ status: 500, response: null })).toBe(false);
    expect(TranslationState.unavailableFor(makePost('8')).size).toBe(0);
  });
});
