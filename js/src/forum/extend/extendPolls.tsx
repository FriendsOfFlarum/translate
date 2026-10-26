import { extend } from 'flarum/common/extend';
import CommentPost from 'flarum/forum/components/CommentPost';
import type ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';
import AbstractPoll from 'ext:fof/polls/forum/components/Poll/AbstractPoll';
import PollOption from 'ext:fof/polls/forum/components/Poll/PollOption';
import PollDraftBadges from 'ext:fof/polls/forum/components/Poll/PollDraftBadges';
import type PollTranslation from '../../common/models/PollTranslation';
import PollTranslationNotice from '../components/PollTranslationNotice';
import TranslateButton from '../components/TranslateButton';
import TranslationState from '../states/TranslationState';
import { actorTargets, isReadersLanguage } from '../utils/targets';

export default function extendPolls() {
  // Post polls render inside CommentPost's retained subtree.
  extend(CommentPost.prototype, 'oninit', function () {
    this.subtree.check(() => {
      const polls = this.attrs.post.polls?.();

      return Array.isArray(polls) ? polls.map((poll) => (poll ? (TranslationState.peek(poll)?.version ?? 0) : 0)).join(',') : '';
    });
  });

  extend(AbstractPoll.prototype, 'headerItems', function (this: AbstractPoll, items: ItemList<Mithril.Children>) {
    const poll = this.attrs.poll;

    if (!poll.id()) return;

    // The backend's guess for the poll, else its post's language; a global poll may have neither.
    const source = poll.detectedLang?.() || this.state?.post?.detectedLang?.() || null;
    const targets = actorTargets();
    const available = isReadersLanguage(source) ? [] : TranslateButton.targetsFor(targets, source, TranslationState.unavailableFor(poll));
    const existing = TranslationState.peek<PollTranslation>(poll);

    if (!available.length && !existing?.showing) return;

    const state = existing ?? TranslationState.for<PollTranslation>(poll, 'polls');
    const translation = state.active();

    if (translation) {
      if (items.has('title')) {
        items.setContent(
          'title',
          <h3 className="Poll-title Poll-title--translated" lang={state.language ?? undefined}>
            {translation.question()}
            <PollDraftBadges poll={poll} />
          </h3>
        );
      }

      const subtitle = translation.subtitle();

      if (subtitle && items.has('subtitle')) {
        items.setContent(
          'subtitle',
          <p className="Poll-subtitle helpText Poll-subtitle--translated" lang={state.language ?? undefined}>
            {subtitle}
          </p>
        );
      }
    }

    if (state.showing) {
      items.add('fof-translate', <PollTranslationNotice state={state} source={source} />, 10);
    } else if (available.length) {
      items.add('fof-translate', <TranslateButton className="Poll-translate" state={state} targets={targets} source={source} />, 10);
    }
  });

  extend(PollOption.prototype, 'textItems', function (this: PollOption, items: ItemList<Mithril.Children>) {
    const poll = this.attrs.state?.poll;
    const translation = poll ? TranslationState.peek<PollTranslation>(poll)?.active() : null;
    const answer = translation?.answerFor(this.attrs.option.id());

    if (answer && items.has('answer')) {
      items.setContent(
        'answer',
        <span className="PollOption-answer PollOption-answer--translated" lang={translation!.language()}>
          {answer}
        </span>
      );
    }
  });
}
