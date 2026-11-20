import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import CommentPost from 'flarum/forum/components/CommentPost';
import type Mithril from 'mithril';
import type Post from 'flarum/common/models/Post';
import type DiscussionTranslation from '../../common/models/DiscussionTranslation';
import type PostTranslation from '../../common/models/PostTranslation';
import TranslateButton from '../components/TranslateButton';
import TranslationBlock from '../components/TranslationBlock';
import TranslationStatus from '../components/TranslationStatus';
import TranslationState from '../states/TranslationState';
import { actorTargets, isReadersLanguage, primaryLanguage } from '../utils/targets';

/**
 * Translating a discussion's first post translates its title too: the two are
 * read together. Quiet, so a failure shows only the post's own error.
 */
export function translateTitleAlongside(post: Post, locale: string) {
  const discussion = post.discussion();

  if (!discussion || !actorTargets().includes(locale)) return;

  const source = discussion.detectedLang?.() ?? null;

  if (source && primaryLanguage(source) === primaryLanguage(locale)) return;
  if (TranslationState.unavailableFor(discussion).has(locale)) return;

  const state = TranslationState.for<DiscussionTranslation>(discussion, 'discussions');

  if (state.loading || state.active()?.language() === locale) return;

  state.translate(locale, false, true);
}

export default function extendCommentPost() {
  extend(CommentPost.prototype, 'oninit', function () {
    this.subtree.check(() => TranslationState.peek(this.attrs.post)?.version);
  });

  extend(CommentPost.prototype, 'actionItems', function (items) {
    const post = this.attrs.post;

    if (post.isHidden() || this.isEditing() || !post.id()) return;

    const targets = actorTargets();
    const source = post.detectedLang?.() ?? null;

    if (isReadersLanguage(source)) return;

    const available = TranslateButton.targetsFor(targets, source, TranslationState.unavailableFor(post));

    if (!available.length) return;

    const state = TranslationState.for<PostTranslation>(post, 'posts');

    if (post.number() === 1) {
      state.onTranslate ??= (locale) => translateTitleAlongside(post, locale);
    }

    // An open translation includes a way back to the original.
    if (state.showing && available.length === 1) return;

    items.add('fof-translate', <TranslateButton state={state} targets={targets} source={source} />, 5);
  });

  extend(CommentPost.prototype, 'content', function (content: Mithril.Children[]) {
    const post = this.attrs.post;
    const state = TranslationState.peek<PostTranslation>(post);

    if (!state?.showing) return;

    if (this.isEditing() || post.isHidden()) {
      // Close without a redraw: we are mid-render, and the version bump forces the next rebuild.
      state.close(false);
      return;
    }

    const source = post.detectedLang?.() ?? null;
    const body = (translation: PostTranslation) => [
      <div className="Post-body TranslationBlock-content" lang={translation.language()}>
        {m.trust(translation.contentHtml())}
      </div>,
      translation.isStale() && (
        <p className="TranslationBlock-stale helpText">{app.translator.trans('fof-translate.forum.translation.stale_text')}</p>
      ),
    ];

    const translation = state.active();
    if (translation?.contentHtml()?.trim() && app.forum.attribute('fof-translate.postTranslationDisplay') === 'replace') {
      content.push(
        <div className="PostTranslation--inPlace">
          {body(translation)}
          <TranslationStatus className="PostTranslation-status" state={state} source={source} />
        </div>
      );
      return;
    }

    content.push(<TranslationBlock className="PostTranslation" state={state} source={source} body={body} />);
  });
}
