import { jest } from '@jest/globals';
import app from 'flarum/forum/app';
import Model from 'flarum/common/Model';
import Discussion from 'flarum/common/models/Discussion';
import type Post from 'flarum/common/models/Post';
import { bootForum } from './fixtures';
import DiscussionTranslation from '../../../src/common/models/DiscussionTranslation';
import PostTranslation from '../../../src/common/models/PostTranslation';
import TranslationState from '../../../src/forum/states/TranslationState';
import { translateTitleAlongside } from '../../../src/forum/extend/extendCommentPost';

function makeDiscussion(id: string, detectedLang: string | null): Discussion {
  return app.store.pushObject<Discussion>({ type: 'discussions', id, attributes: { title: 'Hallo Welt', detectedLang } })!;
}

function makePost(id: string, number: number, discussionId: string): Post {
  return app.store.pushObject<Post>({
    type: 'posts',
    id,
    attributes: { contentType: 'comment', contentHtml: '<p>Hallo</p>', number },
    relationships: { discussion: { data: { type: 'discussions', id: discussionId } } },
  })!;
}

function titlePayload(discussionId: string, language: string) {
  return {
    data: {
      type: 'discussion-translations',
      id: `${discussionId}-${language}`,
      attributes: { language, title: 'Hello world', provider: 'claude', sourceLanguage: 'de', updatedAt: '2026-10-25T10:00:00+00:00' },
    },
  };
}

describe('translating a first post brings its title along', () => {
  beforeAll(() => {
    bootForum();
    app.store.models['post-translations'] = PostTranslation;
    app.store.models['discussion-translations'] = DiscussionTranslation;

    // The jest bootstrap does not run extension extenders; register what Extend.Model would.
    (Discussion.prototype as any).detectedLang = Model.attribute<string | null>('detectedLang');
  });

  afterEach(() => jest.restoreAllMocks());

  test('the onTranslate hook runs on translate, not on refresh', async () => {
    const post = makePost('101', 2, makeDiscussion('11', 'de').id()!);
    jest.spyOn(app, 'request').mockResolvedValue({ data: { type: 'post-translations', id: '101-en', attributes: { language: 'en' } } } as never);

    const state = TranslationState.for<PostTranslation>(post, 'posts');
    const hook = jest.fn();
    state.onTranslate = hook;

    await state.translate('en');
    await state.refresh();

    expect(hook).toHaveBeenCalledTimes(1);
    expect(hook).toHaveBeenCalledWith('en');
  });

  test('the title is translated quietly into the same locale', async () => {
    const discussion = makeDiscussion('12', 'de');
    const post = makePost('102', 1, discussion.id()!);
    const request = jest.spyOn(app, 'request').mockResolvedValue(titlePayload('12', 'en') as never);

    translateTitleAlongside(post, 'en');
    await Promise.resolve();

    expect(request).toHaveBeenCalledTimes(1);
    expect(request.mock.calls[0][0]).toMatchObject({ method: 'POST', url: 'http://localhost/api/discussions/12/translate' });
    expect(typeof (request.mock.calls[0][0] as { errorHandler?: unknown }).errorHandler).toBe('function');
  });

  test('no title request when the title is already in that language, or the locale is not a target', () => {
    const request = jest.spyOn(app, 'request').mockResolvedValue(titlePayload('13', 'en') as never);

    translateTitleAlongside(makePost('103', 1, makeDiscussion('13', 'en').id()!), 'en');
    translateTitleAlongside(makePost('104', 1, makeDiscussion('14', 'de').id()!), 'ja');

    expect(request).not.toHaveBeenCalled();
  });

  test('a title already showing in that locale is not requested again', async () => {
    const discussion = makeDiscussion('15', 'de');
    const request = jest.spyOn(app, 'request').mockResolvedValue(titlePayload('15', 'en') as never);

    await TranslationState.for<DiscussionTranslation>(discussion, 'discussions').translate('en');
    translateTitleAlongside(makePost('105', 1, discussion.id()!), 'en');

    expect(request).toHaveBeenCalledTimes(1);
  });
});
