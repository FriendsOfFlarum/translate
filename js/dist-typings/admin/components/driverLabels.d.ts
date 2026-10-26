import type Mithril from 'mithril';
import driverName from '../../common/utils/driverName';
export { driverName };
export declare function fieldLabel(driver: string, key: string): Mithril.Children;
/**
 * Help text for a driver field; API key fields name the environment variable that overrides them.
 */
export declare function fieldHelp(driver: string, key: string): Mithril.Children;
export declare function optionLabel(driver: string, key: string, value: string): string;
export declare function envVar(driver: string, key: string): string;
