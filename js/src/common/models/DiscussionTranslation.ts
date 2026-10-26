import Model from 'flarum/common/Model';

export default class DiscussionTranslation extends Model {
  language(): string {
    return Model.attribute<string>('language').call(this);
  }

  title(): string {
    return Model.attribute<string>('title').call(this);
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
}
