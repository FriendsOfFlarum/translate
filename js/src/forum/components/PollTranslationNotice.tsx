import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import type PollTranslation from '../../common/models/PollTranslation';
import type TranslationState from '../states/TranslationState';
import LangDisplayName from '../../common/utils/LangDisplayName';
import TranslationMeta from './TranslationMeta';

export interface IPollTranslationNoticeAttrs extends ComponentAttrs {
  state: TranslationState<PollTranslation>;
  source: string | null;
}

/**
 * Strip above a poll whose texts are swapped for a translation, with the
 * "Show original" toggle.
 */
export default class PollTranslationNotice extends Component<IPollTranslationNoticeAttrs> {
  view(): Mithril.Children {
    const { state } = this.attrs;
    const translation = state.active();
    const to = LangDisplayName(state.language ?? '');

    if (!translation) {
      return (
        <div className="PollTranslationNotice">
          <LoadingIndicator display="inline" size="small" />
          <span>{app.translator.trans('fof-translate.forum.translation.loading_text', { to })}</span>
        </div>
      );
    }

    const source = translation.sourceLanguage() || this.attrs.source;
    const refreshLabel = extractText(app.translator.trans('fof-translate.forum.translation.refresh_button'));

    return (
      <div className="PollTranslationNotice">
        <span className="PollTranslationNotice-title">
          {source
            ? app.translator.trans('fof-translate.forum.translation.header_from', { from: LangDisplayName(source), to })
            : app.translator.trans('fof-translate.forum.translation.header_to', { to })}
        </span>
        <TranslationMeta provider={translation.provider()} updatedAt={translation.updatedAt()} />
        <Button className="Button Button--link PollTranslationNotice-original" icon="fas fa-undo" onclick={() => state.close()}>
          {app.translator.trans('fof-translate.forum.poll.show_original_button')}
        </Button>
        {app.forum.attribute<boolean>('fof-translate.canRefresh') && (
          <Button
            className="Button Button--icon Button--link PollTranslationNotice-refresh"
            icon="fas fa-sync"
            aria-label={refreshLabel}
            title={refreshLabel}
            loading={state.loading}
            disabled={state.loading}
            onclick={() => state.refresh()}
          />
        )}
      </div>
    );
  }
}
