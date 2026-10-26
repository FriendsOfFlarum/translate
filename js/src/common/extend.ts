import Extend from 'flarum/common/extenders';
import Post from 'flarum/common/models/Post';
import Discussion from 'flarum/common/models/Discussion';
import PostTranslation from './models/PostTranslation';
import DiscussionTranslation from './models/DiscussionTranslation';
import PollTranslation from './models/PollTranslation';
import PollOptionTranslation from './models/PollOptionTranslation';

export default [
  new Extend.Store()
    .add('post-translations', PostTranslation)
    .add('discussion-translations', DiscussionTranslation)
    .add('poll-translations', PollTranslation)
    .add('poll-option-translations', PollOptionTranslation),

  new Extend.Model(Post).attribute<string | null>('detectedLang'),

  new Extend.Model(Discussion).attribute<string | null>('detectedLang'),
] satisfies object[];
