import Model from 'flarum/common/Model';
export default class PostTranslation extends Model {
    postId(): string;
    language(): string;
    contentHtml(): string;
    provider(): string;
    sourceLanguage(): string | null;
    updatedAt(): Date | null;
    isStale(): boolean;
    isEdited(): boolean;
}
