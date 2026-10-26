import app from 'flarum/forum/app';
import extendCommentPost from './extend/extendCommentPost';
import extendDiscussionHero from './extend/extendDiscussionHero';
import extendPolls from './extend/extendPolls';

export { default as extend } from './extend';

app.initializers.add('fof-translate', () => {
  extendCommentPost();
  extendDiscussionHero();

  if ('fof-polls' in flarum.extensions) {
    extendPolls();
  }
});
