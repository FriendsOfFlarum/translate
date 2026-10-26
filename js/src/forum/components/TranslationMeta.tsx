import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import humanTime from 'flarum/common/helpers/humanTime';
import type Mithril from 'mithril';
import driverName from '../../common/utils/driverName';

export interface ITranslationMetaAttrs extends ComponentAttrs {
  provider: string | null;
  updatedAt: Date | null;
}

/**
 * "Claude · 3 minutes ago" line under a translation.
 */
export default class TranslationMeta extends Component<ITranslationMetaAttrs> {
  view(): Mithril.Children {
    const { provider, updatedAt } = this.attrs;

    if (!provider) return null;

    const providerLabel = driverName(provider);

    return (
      <div className="TranslationMeta">
        {updatedAt
          ? app.translator.trans('fof-translate.forum.translation.meta', { provider: providerLabel, time: humanTime(updatedAt) })
          : providerLabel}
      </div>
    );
  }
}
