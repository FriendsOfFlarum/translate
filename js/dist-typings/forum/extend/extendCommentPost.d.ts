import type Post from 'flarum/common/models/Post';
/**
 * Translating a discussion's first post translates its title too: the two are
 * read together. Quiet, so a failure shows only the post's own error.
 */
export declare function translateTitleAlongside(post: Post, locale: string): void;
export default function extendCommentPost(): void;
