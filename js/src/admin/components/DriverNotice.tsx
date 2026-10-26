import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Alert from 'flarum/common/components/Alert';
import type Mithril from 'mithril';
import { findDriver } from './drivers';

/**
 * Warning at the top of the settings page while no usable driver is saved,
 * e.g. after upgrading from ianm/translate with the removed Google (free) driver.
 */
export default class DriverNotice extends Component {
  view(): Mithril.Children {
    // The saved value: `configured` in the payload describes what is saved, not the unsaved form.
    const driver = findDriver(app.data.settings['fof-translate.driver']);

    if (driver?.configured) return null;

    return (
      <Alert type="warning" dismissible={false} className="FofTranslateDriverNotice">
        {app.translator.trans('fof-translate.admin.settings.disabled_notice')}
      </Alert>
    );
  }
}
