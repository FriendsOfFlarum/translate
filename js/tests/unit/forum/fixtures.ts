import bootstrapForum from '@flarum/jest-config/src/bootstrap/forum';
import { makeUser } from '@flarum/jest-config/factory';
import app from 'flarum/forum/app';
import type Forum from 'flarum/common/models/Forum';

export function bootForum(forumAttributes: Record<string, unknown> = {}) {
  const resources = [
      {
        type: 'forums',
        id: '1',
        attributes: {
          apiUrl: 'http://localhost/api',
          'fof-translate.canTranslate': true,
          'fof-translate.targetLanguages': ['en', 'de', 'fr'],
          'fof-translate.canRefresh': false,
          'fof-translate.useNativeLocaleNames': false,
          ...forumAttributes,
        },
      },
      makeUser({ id: '1', attributes: { id: 1, username: 'admin', displayName: 'Admin' } }),
  ];

  bootstrapForum({ resources });

  // app.forum is normally set by boot(), which also mounts; set it directly.
  app.store.pushPayload({ data: resources });
  app.forum = app.store.getById<Forum>('forums', '1')!;
}
