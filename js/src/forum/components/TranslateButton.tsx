import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Dropdown from 'flarum/common/components/Dropdown';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import type TranslationState from '../states/TranslationState';
import LangDisplayName from '../../common/utils/LangDisplayName';
import { filterTargets, sortTargets } from '../utils/targets';

export interface ITranslateButtonAttrs extends ComponentAttrs {
  state: TranslationState;
  /** Locales the actor may translate into. */
  targets: string[];
  /** Detected language of the content; `null` when unknown. */
  source: string | null;
  buttonClassName?: string;
  className?: string;
  /** Replaces the default action (translating `state`'s subject), e.g. to translate a whole thread. */
  onTranslate?: (locale: string) => unknown;
}

/**
 * "Translate" action: a button for a single target locale, a dropdown for several.
 */
export default class TranslateButton extends Component<ITranslateButtonAttrs> {
  static targetsFor(targets: string[], source: string | null, unavailable?: ReadonlySet<string>): string[] {
    return sortTargets(filterTargets(targets, source, unavailable));
  }

  view(): Mithril.Children {
    const { state, source, className } = this.attrs;
    const buttonClassName = this.attrs.buttonClassName ?? 'Button Button--link';
    const targets = TranslateButton.targetsFor(this.attrs.targets, source, state.unavailable);

    if (!targets.length) return null;

    const label = source
      ? app.translator.trans('fof-translate.forum.translate_button.label_from', { from: LangDisplayName(source) })
      : app.translator.trans('fof-translate.forum.translate_button.label');

    if (targets.length === 1) {
      const target = targets[0];

      // Name the target: "Translate from English" next to English content reads as an offer of English.
      return (
        <Button
          className={classList(buttonClassName, 'TranslateButton', className)}
          icon="fas fa-language"
          loading={state.loading}
          disabled={state.loading}
          title={source ? extractText(label) : undefined}
          onclick={() => this.translate(target)}
        >
          {app.translator.trans('fof-translate.forum.translate_button.target_tooltip', { to: LangDisplayName(target) })}
        </Button>
      );
    }

    const active = state.showing ? state.language : null;

    return (
      <Dropdown
        className={classList('TranslateButton-dropdown', className)}
        buttonClassName={classList(buttonClassName, 'TranslateButton')}
        icon={state.loading ? 'fas fa-spinner fa-spin' : 'fas fa-language'}
        label={label}
        accessibleToggleLabel={extractText(app.translator.trans('fof-translate.forum.translate_button.dropdown_accessible_label'))}
      >
        {targets.map((code) => (
          <Button icon={code === active ? 'fas fa-check' : true} disabled={state.loading} onclick={() => this.translate(code)} lang={code}>
            {LangDisplayName(code)}
          </Button>
        ))}
      </Dropdown>
    );
  }

  translate(locale: string) {
    return this.attrs.onTranslate ? this.attrs.onTranslate(locale) : this.attrs.state.translate(locale);
  }
}
