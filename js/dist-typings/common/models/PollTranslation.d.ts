import Model from 'flarum/common/Model';
import type PollOptionTranslation from './PollOptionTranslation';
export default class PollTranslation extends Model {
    pollId(): string;
    language(): string;
    question(): string;
    subtitle(): string | null;
    imageAlt(): string | null;
    provider(): string;
    sourceLanguage(): string | null;
    updatedAt(): Date | null;
    options(): (PollOptionTranslation | undefined)[] | false;
    /**
     * Translated answer for a poll option id, if the response included one.
     */
    answerFor(optionId: string | undefined): string | null;
}
