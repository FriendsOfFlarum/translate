import Model from 'flarum/common/Model';

export default class PollOptionTranslation extends Model {
  optionId(): string {
    return String(Model.attribute<string | number>('optionId').call(this));
  }

  language(): string {
    return Model.attribute<string>('language').call(this);
  }

  answer(): string {
    return Model.attribute<string>('answer').call(this);
  }
}
