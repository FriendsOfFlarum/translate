import { jest } from '@jest/globals';
import app from 'flarum/forum/app';
import Model from 'flarum/common/Model';
import Discussion from 'flarum/common/models/Discussion';
import Post from 'flarum/common/models/Post';
import { bootForum } from './fixtures';
import DiscussionTranslation from '../../../src/common/models/DiscussionTranslation';
import PostTranslation from '../../../src/common/models/PostTranslation';
import TranslationState from '../../../src/forum/states/TranslationState';
import { closeThread, threadPostsToTranslate, translateThread } from '../../../src/forum/utils/translateThread';

let nextId = 1000;

function discussion(detectedLang: string | null): Discussion {
  return app.store.pushObject<Discussion>({ type: 'discussions', id: String(nextId++), attributes: { title: 'Titel', detectedLang } })!;
}

function post(d: Discussion, number: number, detectedLang: string | null, attributes: Record<string, unknown> = {}): Post {
  return app.store.pushObject<Post>({
    type: 'posts',
    id: String(nextId++),
    attributes: { contentType: 'comment', contentHtml: '<p>x</p>', number, detectedLang, ...attributes },
    relationships: { discussion: { data: { type: 'discussions', id: d.id()! } } },
  })!;
}

const BULK = /\/discussions\/\d+\/translations$/;

function payloadFor(url: string, language: string) {
  if (BULK.test(url)) return { data: [] };

  const [, resource, id] = url.match(/\/(posts|discussions)\/(\d+)\/translate$/)!;
  const type = resource === 'posts' ? 'post-translations' : 'discussion-translations';

  return { data: { type, id: `${id}-${language}`, attributes: { language, contentHtml: '<p>y</p>', title: 'Title', provider: 'claude', updatedAt: '2026-10-25T10:00:00+00:00' } } };
}

describe('translating a whole thread from the hero', () => {
  beforeAll(() => {
    bootForum();
    app.store.models['post-translations'] = PostTranslation;
    app.store.models['discussion-translations'] = DiscussionTranslation;
    // The jest bootstrap does not run extension extenders; register what Extend.Model would.
    (Discussion.prototype as any).detectedLang = Model.attribute<string | null>('detectedLang');
    (Post.prototype as any).detectedLang = Model.attribute<string | null>('detectedLang');
  });

  afterEach(() => jest.restoreAllMocks());

  test('picks the loaded comment posts not in the reader’s language, in thread order', () => {
    const d = discussion('de');
    const german = post(d, 3, 'de');
    const unknown = post(d, 2, null);
    post(d, 1, 'en'); // the reader's own language (en)
    post(d, 4, 'de', { hiddenAt: '2026-10-25T10:00:00+00:00' });
    post(d, 5, null, { contentType: 'discussionRenamed' });
    post(discussion('de'), 1, 'de'); // another discussion

    expect(threadPostsToTranslate(d, 'en')).toEqual([unknown, german]);
  });

  test('skips posts already showing that translation, and posts whose source is the target', () => {
    const d = discussion('fr');
    const showing = post(d, 1, 'fr');
    const alreadyGerman = post(d, 2, 'de');
    const french = post(d, 3, 'fr');

    const state = TranslationState.for<PostTranslation>(showing, 'posts');
    state.translation = app.store.pushObject<PostTranslation>({ type: 'post-translations', id: 'x-de', attributes: { language: 'de' } })!;
    state.language = 'de';
    state.showing = true;

    expect(threadPostsToTranslate(d, 'de')).toEqual([french]);
    expect(threadPostsToTranslate(d, 'de')).not.toContain(alreadyGerman);
  });

  test('translates the title loudly and each post quietly, at most three at once', async () => {
    const d = discussion('de');
    const posts = [1, 2, 3, 4, 5].map((n) => post(d, n, 'de'));
    let inFlight = 0;
    let peak = 0;

    const request = jest.spyOn(app, 'request').mockImplementation(((options: { url: string }) => {
      inFlight++;
      peak = Math.max(peak, inFlight);

      return new Promise((resolve) =>
        setTimeout(() => {
          inFlight--;
          resolve(payloadFor(options.url, 'en'));
        }, 5)
      );
    }) as never);

    await translateThread(d, 'en');

    const urls = request.mock.calls.map((call) => (call[0] as { url: string }).url);
    expect(urls[0]).toBe(`http://localhost/api/discussions/${d.id()}/translations`);
    expect(urls[1]).toBe(`http://localhost/api/discussions/${d.id()}/translate`);
    expect(urls).toHaveLength(7);
    expect(peak).toBeLessThanOrEqual(3);

    const quiet = request.mock.calls.slice(2).map((call) => typeof (call[0] as { errorHandler?: unknown }).errorHandler);
    expect(quiet).toEqual(['function', 'function', 'function', 'function', 'function']);
    expect(posts.every((p) => TranslationState.peek(p)?.showing)).toBe(true);
  });

  test('failed posts produce one summary alert, not one per post', async () => {
    const d = discussion('de');
    [1, 2, 3].map((n) => post(d, n, 'de'));
    const show = jest.spyOn(app.alerts, 'show');

    jest.spyOn(app, 'request').mockImplementation(((options: { url: string }) =>
      options.url.includes('/posts/') ? Promise.reject({ status: 422, response: { errors: [{ source: { pointer: '/data/attributes/translation' } }] } }) : Promise.resolve(payloadFor(options.url, 'en'))) as never);

    await translateThread(d, 'en');

    expect(show).toHaveBeenCalledTimes(1);
  });

  test('show original closes the title and every post translation in the thread', async () => {
    const d = discussion('de');
    const posts = [1, 2].map((n) => post(d, n, 'de'));
    jest.spyOn(app, 'request').mockImplementation(((options: { url: string }) => Promise.resolve(payloadFor(options.url, 'en'))) as never);

    await translateThread(d, 'en');
    closeThread(d);

    expect(TranslationState.peek(d)?.showing).toBe(false);
    expect(posts.some((p) => TranslationState.peek(p)?.showing)).toBe(false);
  });

  test('cached translations come from one bulk lookup; only the misses are translated individually', async () => {
    const d = discussion('de');
    const [first, second, third] = [1, 2, 3].map((n) => post(d, n, 'de'));

    const request = jest.spyOn(app, 'request').mockImplementation(((options: { url: string; body?: any }) => {
      if (BULK.test(options.url)) {
        expect(options.body.data.attributes).toEqual({ locale: 'en', posts: [first.id(), second.id(), third.id()] });

        return Promise.resolve({
          data: [
            { type: 'discussion-translations', id: 't1', attributes: { language: 'en', title: 'Cached title', provider: 'deepl' } },
            { type: 'post-translations', id: 'p1', attributes: { postId: first.id(), language: 'en', contentHtml: '<p>cached 1</p>', provider: 'deepl' } },
            { type: 'post-translations', id: 'p3', attributes: { postId: third.id(), language: 'en', contentHtml: '<p>cached 3</p>', provider: 'deepl' } },
          ],
        });
      }

      return Promise.resolve(payloadFor(options.url, 'en'));
    }) as never);

    await translateThread(d, 'en');

    const urls = request.mock.calls.map((call) => (call[0] as { url: string }).url);
    expect(urls).toEqual([`http://localhost/api/discussions/${d.id()}/translations`, `http://localhost/api/posts/${second.id()}/translate`]);

    expect(TranslationState.peek<DiscussionTranslation>(d)?.active()?.title()).toBe('Cached title');
    expect(TranslationState.peek<PostTranslation>(first)?.active()?.contentHtml()).toBe('<p>cached 1</p>');
    expect(TranslationState.peek<PostTranslation>(third)?.active()?.contentHtml()).toBe('<p>cached 3</p>');
    expect(TranslationState.peek(second)?.showing).toBe(true);
  });

  test('if the bulk lookup fails, everything is translated individually', async () => {
    const d = discussion('de');
    [1, 2].map((n) => post(d, n, 'de'));

    const request = jest.spyOn(app, 'request').mockImplementation(((options: { url: string }) =>
      BULK.test(options.url) ? Promise.reject({ status: 429 }) : Promise.resolve(payloadFor(options.url, 'en'))) as never);

    await translateThread(d, 'en');

    expect(request.mock.calls.map((call) => (call[0] as { url: string }).url).filter((url) => !BULK.test(url))).toHaveLength(3);
  });
});
