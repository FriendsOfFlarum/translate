import Extend from 'flarum/common/extenders';
import type Application from 'flarum/common/Application';
import type IExtender from 'flarum/common/extenders/IExtender';
import type { IExtensionModule } from 'flarum/common/extenders/IExtender';
import Poll from 'ext:fof/polls/forum/models/Poll';
import commonExtend from '../common/extend';

/**
 * Applies the wrapped extenders only when fof/polls is enabled; its models
 * resolve to placeholders otherwise.
 */
const whenPolls = (extenders: () => IExtender[]): IExtender => ({
  extend(app: Application, extension: IExtensionModule) {
    if (!('fof-polls' in flarum.extensions)) return;

    extenders().forEach((extender) => extender.extend(app, extension));
  },
});

export default [...commonExtend, whenPolls(() => [new Extend.Model(Poll).attribute<string | null>('detectedLang')])] satisfies object[];
