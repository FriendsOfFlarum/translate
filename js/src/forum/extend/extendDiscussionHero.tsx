import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import DiscussionHero from 'flarum/forum/components/DiscussionHero';
import type Discussion from 'flarum/common/models/Discussion';
import type DiscussionTranslation from '../../common/models/DiscussionTranslation';
import TranslateButton from '../components/TranslateButton';
import TranslationStatus from '../components/TranslationStatus';
import TranslationState from '../states/TranslationState';
import { actorTargets, isReadersLanguage } from '../utils/targets';
import { closeThread, translateThread } from '../utils/translateThread';

/**
 * The hero title is swapped for its translation in place, with a one-line
 * status underneath; the translate control sits on its own line below the title.
 * The hero acts on the whole thread: translating translates the title and every
 * loaded post that needs it; "Show original" reverts them all.
 */
export default function extendDiscussionHero() {
  extend(DiscussionHero.prototype, 'items', function (items) {
    const discussion = this.attrs.discussion;

    if (!discussion.id()) return;

    const existing = TranslationState.peek<DiscussionTranslation>(discussion);
    const translation = existing?.active() ?? null;
    const source = discussion.detectedLang?.() ?? null;

    syncDocumentTitle(discussion, translation);

    if (existing && translation) {
      if (items.has('title')) {
        items.setContent(
          'title',
          <h1 className="DiscussionHero-title DiscussionHero-title--translated" lang={translation.language()}>
            {translation.title()}
          </h1>
        );
      }

      items.add(
        'fof-translate',
        <TranslationStatus className="DiscussionHero-translation" state={existing} source={source} onClose={() => closeThread(discussion)} />,
        -10
      );

      return;
    }

    const targets = actorTargets();
    const available = isReadersLanguage(source) ? [] : TranslateButton.targetsFor(targets, source, TranslationState.unavailableFor(discussion));

    if (!available.length) return;

    const state = existing ?? TranslationState.for<DiscussionTranslation>(discussion, 'discussions');

    items.add(
      'fof-translate',
      <TranslateButton
        className="DiscussionHero-translate"
        buttonClassName="Button Button--link"
        state={state}
        targets={targets}
        source={source}
        onTranslate={(locale: string) => translateThread(discussion, locale)}
      />,
      -10
    );
  });
}

/**
 * Keep the browser tab title in step with the hero: translated while the
 * translation is shown, the original otherwise.
 */
function syncDocumentTitle(discussion: Discussion, translation: DiscussionTranslation | null) {
  if (!app.viewingDiscussion(discussion)) return;

  const title = translation?.title() || discussion.title();

  if (title && app.title !== title) app.setTitle(title);
}
