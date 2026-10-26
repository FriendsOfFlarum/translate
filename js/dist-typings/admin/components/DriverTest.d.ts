import Component, { type ComponentAttrs } from 'flarum/common/Component';
import type ExtensionPage from 'flarum/admin/components/ExtensionPage';
import type Mithril from 'mithril';
import type { DriverInfo } from './types';
export interface DriverTestResult {
    ok: boolean;
    driver: string;
    durationMs: number;
    source: {
        language: string;
        text: string;
    };
    target: {
        language: string;
    };
    translation: {
        text: string;
        html: string;
    } | null;
    detectedSourceLanguage: string | null;
    markupPreserved: boolean | null;
    error: string | null;
}
interface DriverTestState {
    /** Driver the result belongs to; a different selection clears it. */
    driver: string | null;
    loading: boolean;
    result: DriverTestResult | null;
}
export interface IDriverTestAttrs extends ComponentAttrs {
    page: ExtensionPage;
    driver: DriverInfo;
}
/**
 * "Test translation" for the selected driver, using the unsaved form values.
 */
export default class DriverTest extends Component<IDriverTestAttrs> {
    testState(): DriverTestState;
    /**
     * Current form values for this driver and the shared AI settings, minus
     * fields the environment overrides.
     */
    formSettings(): Record<string, unknown>;
    run(): void;
    view(): Mithril.Children;
    resultView(result: DriverTestResult): Mithril.Children;
}
export {};
