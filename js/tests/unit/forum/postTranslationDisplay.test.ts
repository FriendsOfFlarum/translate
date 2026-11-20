import { jest } from '@jest/globals';
import app from 'flarum/forum/app';
import type Post from 'flarum/common/models/Post';
import CommentPost from 'flarum/forum/components/CommentPost';
import PostTranslation from '../../../src/common/models/PostTranslation';
import extendCommentPost from '../../../src/forum/extend/extendCommentPost';
import TranslationState from '../../../src/forum/states/TranslationState';
import { bootForum } from './fixtures';

describe('post translation display', () => {
  beforeAll(() => {
    bootForum({ 'fof-translate.targetLanguages': ['en'] });
    app.store.models['post-translations'] = PostTranslation;
    app.store.pushObject({ type: 'discussions', id: '1', attributes: { slug: '1-discussion' } });
    extendCommentPost();
  });

  afterEach(() => jest.restoreAllMocks());

  test.each([
    ['below', '<p>Hello</p>', false],
    ['replace', '<p>Hello</p>', true],
    ['replace', '', false],
  ])('%s with translation HTML %j controls when the original may be hidden', async (mode, contentHtml, canReplace) => {
    app.forum.pushAttributes({ 'fof-translate.postTranslationDisplay': mode });
    const post = app.store.pushObject<Post>({
      type: 'posts',
      id: String(app.store.all('posts').length + 1),
      attributes: { contentType: 'comment', contentHtml: '<p>Hallo</p>', number: 2 },
      relationships: { discussion: { data: { type: 'discussions', id: '1' } } },
    })!;
    const state = TranslationState.for<PostTranslation>(post, 'posts');
    const payload = {
      data: {
        type: 'post-translations',
        id: post.id() + '-en',
        attributes: { language: 'en', contentHtml, sourceLanguage: 'de', provider: 'google' },
      },
    };
    let respond!: (value: typeof payload) => void;
    const response = new Promise<typeof payload>((resolve) => {
      respond = resolve;
    });
    const request = jest.spyOn(app, 'request').mockReturnValue(response as never);
    const component = new CommentPost();
    component.attrs = { post };
    const root = document.createElement('div');
    const render = () => m.render(root, component.content());

    render();
    expect(root.textContent).toContain('Hallo');
    expect(root.querySelector('.PostTranslation--inPlace')).toBeNull();
    expect(request).not.toHaveBeenCalled();

    const pending = state.translate('en');
    render();
    expect(root.textContent).toContain('Hallo');
    expect(root.querySelector('.PostTranslation--inPlace')).toBeNull();

    respond(payload);
    await pending;
    render();

    const replacement = root.querySelector('.PostTranslation--inPlace');
    expect(Boolean(replacement)).toBe(canReplace);
    if (replacement) {
      expect(replacement.textContent).toContain('Hello');
      replacement.querySelector<HTMLButtonElement>('.TranslationStatus-original')!.click();
      render();
      expect(root.textContent).toContain('Hallo');
      expect(root.textContent).not.toContain('Hello');
    }
    expect(request).toHaveBeenCalledTimes(1);
    m.render(root, null);
  });
});
