import Component from 'flarum/common/Component';
import type Mithril from 'mithril';
/**
 * Warning at the top of the settings page while no usable driver is saved,
 * e.g. after upgrading from ianm/translate with the removed Google (free) driver.
 */
export default class DriverNotice extends Component {
    view(): Mithril.Children;
}
