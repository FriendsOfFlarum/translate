import app from 'flarum/forum/app';
import type Discussion from 'flarum/common/models/Discussion';
import type Post from 'flarum/common/models/Post';
import DiscussionTranslation from '../../common/models/DiscussionTranslation';
import PostTranslation from '../../common/models/PostTranslation';
import TranslationState from '../states/TranslationState';
import { filterTargets, isReadersLanguage } from './targets';

/**
 * Requests in flight at once when a whole thread is translated: enough to fill a
 * screen quickly without flooding the server, the provider or the throttle.
 */
export const THREAD_CONCURRENCY = 3;

/**
 * The thread's loaded comment posts the hero's translate action covers, in thread
 * order: the ones a post's own translate button would offer `locale` for, and that
 * are not already showing it. Posts loaded later (by scrolling) are not included.
 */
export function threadPostsToTranslate(discussion: Discussion, locale: string): Post[] {
  return app.store
    .all<Post>('posts')
    .filter((post) => post.discussion() === discussion && post.contentType() === 'comment' && !post.isHidden())
    .filter((post) => {
      const source = post.detectedLang?.() ?? null;

      if (isReadersLanguage(source) || !filterTargets([locale], source, TranslationState.unavailableFor(post)).length) return false;

      const state = TranslationState.peek(post);

      return !(state?.loading || state?.active()?.language() === locale);
    })
    .sort((a, b) => (a.number() ?? 0) - (b.number() ?? 0));
}

/**
 * Every cached, current translation of the thread in one request, adopted straight
 * into the title's and posts' states. Returns what was found; a failed lookup finds
 * nothing, so everything is then translated individually.
 */
async function adoptCached(discussion: Discussion, locale: string, posts: Post[]): Promise<{ title: boolean; posts: Set<Post> }> {
  const found = { title: false, posts: new Set<Post>() };

  let records: unknown;

  try {
    const payload = await app.request<any>({
      method: 'POST',
      url: `${app.forum.attribute<string>('apiUrl')}/discussions/${discussion.id()}/translations`,
      body: { data: { attributes: { locale, posts: posts.map((post) => post.id()) } } },
      errorHandler: () => {},
    });

    records = app.store.pushPayload(payload);
  } catch {
    return found;
  }

  const byId = new Map(posts.map((post) => [post.id(), post]));

  for (const record of Array.isArray(records) ? records : []) {
    if (record instanceof DiscussionTranslation) {
      TranslationState.for<DiscussionTranslation>(discussion, 'discussions').adopt(record);
      found.title = true;
    } else if (record instanceof PostTranslation && byId.has(record.postId())) {
      const post = byId.get(record.postId())!;

      TranslationState.for<PostTranslation>(post, 'posts').adopt(record);
      found.posts.add(post);
    }
  }

  return found;
}

/**
 * The hero's translate action: the title, then every loaded post that needs it.
 * Cached translations arrive in one bulk lookup; only the misses are translated, at
 * most THREAD_CONCURRENCY at a time. Post requests are quiet; their failures are
 * summed into a single alert.
 */
export async function translateThread(discussion: Discussion, locale: string): Promise<void> {
  const title = TranslationState.for<DiscussionTranslation>(discussion, 'discussions');
  const needsTitle = title.active()?.language() !== locale;
  const posts = threadPostsToTranslate(discussion, locale);

  if (!needsTitle && !posts.length) return;

  const cached = await adoptCached(discussion, locale, posts);

  let failed = 0;
  const queue: Array<() => Promise<unknown>> = posts
    .filter((post) => !cached.posts.has(post))
    .map((post) => async () => {
      const translation = await TranslationState.for<PostTranslation>(post, 'posts').translate(locale, false, true);

      if (!translation) failed++;
    });

  // The title goes first, within the same limit; it is what the reader clicked, so its errors are not quiet.
  if (needsTitle && !cached.title) queue.unshift(() => title.translate(locale));

  const worker = async () => {
    for (let job = queue.shift(); job; job = queue.shift()) await job();
  };

  await Promise.all(Array.from({ length: Math.min(THREAD_CONCURRENCY, queue.length) }, worker));

  if (failed) {
    app.alerts.show({ type: 'error' }, app.translator.trans('fof-translate.forum.translation.thread_failed_text', { count: failed }));
  }
}

/**
 * The hero's "Show original": one click opened the thread's translations, one closes them.
 */
export function closeThread(discussion: Discussion): void {
  TranslationState.peek(discussion)?.close(false);

  app.store
    .all<Post>('posts')
    .filter((post) => post.discussion() === discussion)
    .forEach((post) => TranslationState.peek(post)?.close(false));

  m.redraw();
}
