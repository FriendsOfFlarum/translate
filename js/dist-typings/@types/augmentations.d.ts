import 'flarum/common/models/Post';
import 'flarum/common/models/Discussion';
import 'ext:fof/polls/forum/models/Poll';

declare module 'flarum/common/models/Post' {
  export default interface Post {
    detectedLang(): string | null;
  }
}

declare module 'flarum/common/models/Discussion' {
  export default interface Discussion {
    detectedLang(): string | null;
  }
}

declare module 'ext:fof/polls/forum/models/Poll' {
  export default interface Poll {
    detectedLang(): string | null;
  }
}
