import type { DriverInfo } from './types';
export declare const RECOMMENDED_DRIVER = "claude";
export declare const AI_DRIVERS: string[];
/**
 * Drivers from the admin payload, the recommended one first, then the
 * bundled ones, then third-party drivers alphabetically.
 */
export declare function drivers(): DriverInfo[];
export declare function findDriver(name: string | null | undefined): DriverInfo | undefined;
