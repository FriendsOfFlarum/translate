import app from 'flarum/admin/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import formatNumber from 'flarum/common/utils/formatNumber';
import type ExtensionPage from 'flarum/admin/components/ExtensionPage';
import type Mithril from 'mithril';

export type DetectionType = 'posts' | 'discussions';

type Counts = Record<DetectionType, { total: number; pending: number; undetermined: number }>;

interface BatchResult {
  processed: number;
  detected: number;
  after: number | null;
  done: boolean;
}

interface Progress {
  type: DetectionType;
  processed: number;
  expected: number;
}

type Totals = Record<DetectionType, { processed: number; detected: number }>;

const TYPES: DetectionType[] = ['posts', 'discussions'];

/**
 * Counts and backfill-run state, kept per settings page visit so it survives
 * redraws and remounts, and counts load only once per visit.
 */
export class DetectionState {
  counts: Counts | null = null;
  loadingCounts = false;
  running = false;
  cancelled = false;
  force = false;
  progress: Progress | null = null;
  totals: Totals | null = null;
  /** `done`, `cancelled` or `failed` once a run has ended. */
  outcome: 'done' | 'cancelled' | 'failed' | null = null;

  url(): string {
    return `${app.forum.attribute<string>('apiUrl')}/fof-translate/detection`;
  }

  loadCounts(): Promise<void> {
    this.loadingCounts = true;
    m.redraw();

    return app
      .request<{ data: Counts }>({ method: 'GET', url: this.url() })
      .then((response) => {
        this.counts = response.data;
      })
      .catch(() => {})
      .finally(() => {
        this.loadingCounts = false;
        m.redraw();
      });
  }

  async run(force: boolean): Promise<void> {
    if (this.running) return;

    this.running = true;
    this.cancelled = false;
    this.force = force;
    this.outcome = null;
    this.totals = { posts: { processed: 0, detected: 0 }, discussions: { processed: 0, detected: 0 } };
    m.redraw();

    try {
      for (const type of TYPES) {
        const count = this.counts?.[type];
        this.progress = { type, processed: 0, expected: (force ? count?.total : count?.pending) ?? 0 };

        let after: number | null = null;
        let done = false;

        while (!done && !this.cancelled) {
          const batch: BatchResult = await this.batch(type, force, after);

          this.totals[type].processed += batch.processed;
          this.totals[type].detected += batch.detected;
          this.progress.processed += batch.processed;
          after = batch.after;
          // A batch that processed nothing cannot advance; stop rather than loop.
          done = batch.done || after === null || batch.processed === 0;
          m.redraw();
        }

        if (this.cancelled) break;
      }

      this.outcome = this.cancelled ? 'cancelled' : 'done';
    } catch {
      // app.request has shown the error alert.
      this.outcome = 'failed';
    } finally {
      this.running = false;
      this.progress = null;
      m.redraw();
      this.loadCounts();
    }
  }

  batch(type: DetectionType, force: boolean, after: number | null): Promise<BatchResult> {
    return app
      .request<{ data: BatchResult }>({
        method: 'POST',
        url: this.url(),
        body: { type, force, after },
      })
      .then((response) => response.data);
  }

  cancel() {
    this.cancelled = true;
  }
}

const states = new WeakMap<ExtensionPage, DetectionState>();

export interface ILanguageDetectionAttrs extends ComponentAttrs {
  page: ExtensionPage;
}

/**
 * Backfills `detected_lang` for content written before the extension was
 * installed, batch by batch, so the translate button can hide itself on
 * content already in the reader's language.
 */
export default class LanguageDetection extends Component<ILanguageDetectionAttrs> {
  detection!: DetectionState;

  oninit(vnode: Mithril.Vnode<ILanguageDetectionAttrs, this>) {
    super.oninit(vnode);

    let state = states.get(this.attrs.page);

    if (!state) {
      state = new DetectionState();
      states.set(this.attrs.page, state);
      state.loadCounts();
    }

    this.detection = state;
  }

  view(): Mithril.Children {
    const state = this.detection;

    return (
      <div className="Form-group FofTranslateDetection">
        <label>{app.translator.trans('fof-translate.admin.detection.heading')}</label>
        <p className="helpText">
          {app.translator.trans('fof-translate.admin.detection.help', { command: <code>php flarum fof:translate:detect</code> })}
        </p>

        {this.countsView()}
        {state.running ? this.progressView() : this.summaryView()}

        <div className="FofTranslateDetection-actions">
          <Button
            className="Button Button--primary"
            icon="fas fa-search"
            disabled={state.running || state.loadingCounts}
            onclick={() => state.run(false)}
          >
            {app.translator.trans('fof-translate.admin.detection.detect_missing_button')}
          </Button>
          <Button className="Button" icon="fas fa-redo" disabled={state.running || state.loadingCounts} onclick={() => this.redetectAll()}>
            {app.translator.trans('fof-translate.admin.detection.redetect_all_button')}
          </Button>
          {state.running && (
            <Button className="Button Button--danger" icon="fas fa-stop" disabled={state.cancelled} onclick={() => state.cancel()}>
              {app.translator.trans('fof-translate.admin.detection.cancel_button')}
            </Button>
          )}
        </div>
      </div>
    );
  }

  redetectAll() {
    if (confirm(extractText(app.translator.trans('fof-translate.admin.detection.redetect_all_confirm')))) {
      this.detection.run(true);
    }
  }

  countsView(): Mithril.Children {
    const state = this.detection;

    if (state.loadingCounts && !state.counts) return <LoadingIndicator display="block" size="small" />;

    return (
      <div className="FofTranslateDetection-counts">
        {state.counts ? (
          <div>
            <ul>
              <li>{app.translator.trans('fof-translate.admin.detection.posts_status_text', this.statusParams(state.counts.posts))}</li>
              <li>{app.translator.trans('fof-translate.admin.detection.discussions_status_text', this.statusParams(state.counts.discussions))}</li>
            </ul>
            {state.counts.posts.undetermined + state.counts.discussions.undetermined > 0 && (
              <p className="helpText">{app.translator.trans('fof-translate.admin.detection.undetermined_help')}</p>
            )}
          </div>
        ) : (
          <p className="helpText">{app.translator.trans('fof-translate.admin.detection.counts_failed_text')}</p>
        )}
        <Button
          className="Button Button--link FofTranslateDetection-refresh"
          icon="fas fa-sync"
          loading={state.loadingCounts}
          disabled={state.loadingCounts || state.running}
          onclick={() => state.loadCounts()}
        >
          {app.translator.trans('fof-translate.admin.detection.refresh_button')}
        </Button>
      </div>
    );
  }

  /**
   * `total` stays a number for the plural; the rest are formatted like the plural's `#`.
   */
  statusParams(count: Counts[DetectionType]): Record<string, number | string> {
    return {
      total: count.total,
      detected: formatNumber(count.total - count.pending - count.undetermined),
      undetermined: formatNumber(count.undetermined),
      pending: formatNumber(count.pending),
    };
  }

  progressView(): Mithril.Children {
    const progress = this.detection.progress;

    if (!progress) return null;

    const percent = progress.expected > 0 ? Math.min(100, Math.round((progress.processed / progress.expected) * 100)) : 0;
    const label =
      progress.type === 'posts'
        ? app.translator.trans('fof-translate.admin.detection.progress_posts_text', { processed: progress.processed, expected: progress.expected })
        : app.translator.trans('fof-translate.admin.detection.progress_discussions_text', {
            processed: progress.processed,
            expected: progress.expected,
          });

    return (
      <div className="FofTranslateDetection-progress">
        <div
          className="FofTranslateDetection-bar"
          role="progressbar"
          aria-valuemin={0}
          aria-valuemax={100}
          aria-valuenow={percent}
          aria-label={extractText(label)}
        >
          <div className="FofTranslateDetection-barFill" style={{ width: `${percent}%` }} />
        </div>
        <p className="helpText">{label}</p>
      </div>
    );
  }

  summaryView(): Mithril.Children {
    const { outcome, totals } = this.detection;

    if (!outcome || !totals) return null;

    const detected = totals.posts.detected + totals.discussions.detected;
    const params = {
      posts: totals.posts.processed,
      discussions: totals.discussions.processed,
      detected: formatNumber(detected),
      undetermined: formatNumber(totals.posts.processed + totals.discussions.processed - detected),
    };

    let text: Mithril.Children;

    if (outcome === 'done') {
      text = app.translator.trans('fof-translate.admin.detection.summary_done_text', params);
    } else if (outcome === 'cancelled') {
      text = app.translator.trans('fof-translate.admin.detection.summary_cancelled_text', params);
    } else {
      text = app.translator.trans('fof-translate.admin.detection.summary_failed_text', params);
    }

    return <p className="FofTranslateDetection-summary">{text}</p>;
  }
}
