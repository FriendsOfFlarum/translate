import Component, { type ComponentAttrs } from 'flarum/common/Component';
import type ExtensionPage from 'flarum/admin/components/ExtensionPage';
import type Mithril from 'mithril';
import type { DriverField, DriverInfo } from './types';
export interface IDriverSettingsAttrs extends ComponentAttrs {
    page: ExtensionPage;
}
/**
 * Fields of the currently selected driver, built from the `fof-translate.drivers` payload.
 */
export default class DriverSettings extends Component<IDriverSettingsAttrs> {
    view(): Mithril.Children;
    field(driver: DriverInfo, key: string, field: DriverField): Mithril.Children;
    options(driver: string, key: string, options: DriverField['options']): Record<string, string>;
    aiFields(): Mithril.Children;
}
