import Model from 'flarum/common/Model';

export default class PostTranslation extends Model {
  postId(): string {
    return Model.attribute<string>('postId').call(this);
  }

  language(): string {
    return Model.attribute<string>('language').call(this);
  }

  contentHtml(): string {
    return Model.attribute<string>('contentHtml').call(this);
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

  isStale(): boolean {
    return Model.attribute<boolean>('isStale').call(this);
  }

  isEdited(): boolean {
    return Model.attribute<boolean>('isEdited').call(this);
  }
}
