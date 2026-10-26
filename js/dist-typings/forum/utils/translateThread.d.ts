import type Discussion from 'flarum/common/models/Discussion';
import type Post from 'flarum/common/models/Post';
/**
 * Requests in flight at once when a whole thread is translated: enough to fill a
 * screen quickly without flooding the server, the provider or the throttle.
 */
export declare const THREAD_CONCURRENCY = 3;
/**
 * Post ids per bulk lookup: the most the endpoint reads (BulkTranslationsEndpoint::MAX_POSTS).
 */
export declare const BULK_LOOKUP_SIZE = 50;
/**
 * The thread's loaded comment posts the hero's translate action covers, in thread
 * order: the ones a post's own translate button would offer `locale` for, and that
 * are not already showing it. Posts loaded later (by scrolling) are not included.
 */
export declare function threadPostsToTranslate(discussion: Discussion, locale: string): Post[];
/**
 * The hero's translate action: the title, then every loaded post that needs it.
 * Cached translations arrive in one bulk lookup; only the misses are translated, at
 * most THREAD_CONCURRENCY at a time. Post requests are quiet; their failures are
 * summed into a single alert.
 */
export declare function translateThread(discussion: Discussion, locale: string): Promise<void>;
/**
 * The hero's "Show original": one click opened the thread's translations, one closes them.
 */
export declare function closeThread(discussion: Discussion): void;
