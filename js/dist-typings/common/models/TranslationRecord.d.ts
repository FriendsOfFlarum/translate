import type Model from 'flarum/common/Model';
/**
 * Attributes shared by every translation resource type.
 */
export default interface TranslationRecord extends Model {
    language(): string;
    provider(): string;
    sourceLanguage(): string | null;
    updatedAt(): Date | null;
}
