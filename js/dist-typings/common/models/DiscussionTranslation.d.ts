import Model from 'flarum/common/Model';
export default class DiscussionTranslation extends Model {
    language(): string;
    title(): string;
    provider(): string;
    sourceLanguage(): string | null;
    updatedAt(): Date | null;
}
