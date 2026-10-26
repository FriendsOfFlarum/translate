import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Tooltip from 'flarum/common/components/Tooltip';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import type TranslationRecord from '../../common/models/TranslationRecord';
import LangDisplayName from '../../common/utils/LangDisplayName';
import driverName from '../../common/utils/driverName';
import type TranslationState from '../states/TranslationState';

export interface ITranslationStatusAttrs extends ComponentAttrs {
  state: TranslationState;
  /** Detected language of the original, used when the provider reported none. */
  source: string | null;
  className?: string;
  /** Replaces the default "Show original" (closing `state`), e.g. to close a whole thread. */
  onClose?: () => void;
}

/**
 * One-line status under content whose text has been swapped for a translation
 * in place: "Translated from German · Show original · ↻". Colours inherit from
 * the surrounding element, so it reads on tag-coloured heroes too.
 */
export default class TranslationStatus extends Component<ITranslationStatusAttrs> {
  view(): Mithril.Children {
    const { state, className } = this.attrs;
    const translation = state.active();

    if (!translation) return null;

    const source = translation.sourceLanguage() || this.attrs.source;

    return (
      <div className={classList('TranslationStatus', className)} aria-live="polite">
        <Tooltip text={this.meta(translation)}>
          <span className="TranslationStatus-text">
            <Icon name="fas fa-language" />
            {source
              ? app.translator.trans('fof-translate.forum.translation.status_from', { from: LangDisplayName(source) })
              : app.translator.trans('fof-translate.forum.translation.status_text')}
          </span>
        </Tooltip>
        <Button
          className="Button Button--link TranslationStatus-original"
          onclick={() => (this.attrs.onClose ? this.attrs.onClose() : state.close())}
        >
          {app.translator.trans('fof-translate.forum.translation.show_original_button')}
        </Button>
        {this.refreshButton()}
      </div>
    );
  }

  meta(translation: TranslationRecord): string {
    const provider = driverName(translation.provider() ?? '');
    const updatedAt = translation.updatedAt();

    return updatedAt
      ? extractText(app.translator.trans('fof-translate.forum.translation.meta', { provider, time: dayjs(updatedAt).fromNow() }))
      : provider;
  }

  refreshButton(): Mithril.Children {
    const { state } = this.attrs;

    if (!app.forum.attribute<boolean>('fof-translate.canRefresh')) return null;

    const label = extractText(app.translator.trans('fof-translate.forum.translation.refresh_button'));

    return (
      <Tooltip text={label}>
        <Button
          className="Button Button--icon Button--link TranslationStatus-refresh"
          icon="fas fa-sync"
          aria-label={label}
          loading={state.loading}
          disabled={state.loading}
          onclick={() => state.refresh()}
        />
      </Tooltip>
    );
  }
}
