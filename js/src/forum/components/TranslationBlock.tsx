import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Tooltip from 'flarum/common/components/Tooltip';
import ItemList from 'flarum/common/utils/ItemList';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import type TranslationRecord from '../../common/models/TranslationRecord';
import type Mithril from 'mithril';
import type TranslationState from '../states/TranslationState';
import LangDisplayName from '../../common/utils/LangDisplayName';
import TranslationMeta from './TranslationMeta';

export interface ITranslationBlockAttrs<T extends TranslationRecord = TranslationRecord> extends ComponentAttrs {
  state: TranslationState<T>;
  /** Detected language of the original, used when the provider reported none. */
  source: string | null;
  /** Renders the translated body from the active translation. */
  body: (translation: T) => Mithril.Children;
  className?: string;
}

/**
 * Translated content under the original: header with close/refresh, body, meta footer.
 */
export default class TranslationBlock<T extends TranslationRecord = TranslationRecord> extends Component<ITranslationBlockAttrs<T>> {
  view(): Mithril.Children {
    const { state, className } = this.attrs;

    if (!state.showing) return null;

    const current = state.active();

    return (
      <section className={classList('TranslationBlock', className, state.loading && 'TranslationBlock--loading')} lang={state.language ?? undefined}>
        <header className="TranslationBlock-header">
          <span className="TranslationBlock-title">{this.title(current)}</span>
          <ul className="TranslationBlock-controls">
            {this.controlItems()
              .toArray()
              .map((item) => (
                <li>{item}</li>
              ))}
          </ul>
        </header>
        {current ? <div className="TranslationBlock-body">{this.attrs.body(current)}</div> : <LoadingIndicator display="block" size="small" />}
        {current && (
          <footer className="TranslationBlock-footer">
            <TranslationMeta provider={current.provider()} updatedAt={current.updatedAt()} />
          </footer>
        )}
      </section>
    );
  }

  title(translation: T | null): Mithril.Children {
    const { state } = this.attrs;
    const source = translation?.sourceLanguage() || this.attrs.source;
    const to = LangDisplayName(state.language ?? '');

    if (state.loading && !translation) {
      return app.translator.trans('fof-translate.forum.translation.loading_text', { to });
    }

    return source
      ? app.translator.trans('fof-translate.forum.translation.header_from', { from: LangDisplayName(source), to })
      : app.translator.trans('fof-translate.forum.translation.header_to', { to });
  }

  controlItems(): ItemList<Mithril.Children> {
    const items = new ItemList<Mithril.Children>();
    const { state } = this.attrs;

    if (app.forum.attribute<boolean>('fof-translate.canRefresh') && state.translation) {
      const label = extractText(app.translator.trans('fof-translate.forum.translation.refresh_button'));

      items.add(
        'refresh',
        <Tooltip text={label}>
          <Button
            className="Button Button--icon Button--link TranslationBlock-refresh"
            icon="fas fa-sync"
            aria-label={label}
            loading={state.loading}
            disabled={state.loading}
            onclick={() => state.refresh()}
          />
        </Tooltip>,
        10
      );
    }

    const closeLabel = extractText(app.translator.trans('fof-translate.forum.translation.close_button'));

    items.add(
      'close',
      <Tooltip text={closeLabel}>
        <Button
          className="Button Button--icon Button--link TranslationBlock-close"
          icon="fas fa-times"
          aria-label={closeLabel}
          onclick={() => state.close()}
        />
      </Tooltip>,
      0
    );

    return items;
  }
}
