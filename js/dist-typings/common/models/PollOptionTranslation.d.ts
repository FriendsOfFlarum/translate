import Model from 'flarum/common/Model';
export default class PollOptionTranslation extends Model {
    optionId(): string;
    language(): string;
    answer(): string;
}
