import Model from 'flarum/common/Model';
import type PollOptionTranslation from './PollOptionTranslation';

export default class PollTranslation extends Model {
  pollId(): string {
    return String(Model.attribute<string | number>('pollId').call(this));
  }

  language(): string {
    return Model.attribute<string>('language').call(this);
  }

  question(): string {
    return Model.attribute<string>('question').call(this);
  }

  subtitle(): string | null {
    return Model.attribute<string | null>('subtitle').call(this);
  }

  imageAlt(): string | null {
    return Model.attribute<string | null>('imageAlt').call(this);
  }

  provider(): string {
    return Model.attribute<string>('provider').call(this);
  }

  sourceLanguage(): string | null {
    return Model.attribute<string | null>('sourceLanguage').call(this);
  }

  updatedAt(): Date | null {
    return Model.attribute<Date | null, string | null>('updatedAt', Model.transformDate).call(this);
  }

  options(): (PollOptionTranslation | undefined)[] | false {
    return Model.hasMany<PollOptionTranslation>('options').call(this);
  }

  /**
   * Translated answer for a poll option id, if the response included one.
   */
  answerFor(optionId: string | undefined): string | null {
    const options = this.options();

    if (!options || !optionId) return null;

    return options.find((option) => option?.optionId() === optionId)?.answer() ?? null;
  }
}
