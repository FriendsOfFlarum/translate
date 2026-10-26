import app from 'flarum/admin/app';
import type { DriverInfo, DriverPayload } from './types';

const order = ['claude', 'openai', 'ollama', 'deepl', 'google'];

export const RECOMMENDED_DRIVER = 'claude';

export const AI_DRIVERS = ['claude', 'openai', 'ollama'];

/**
 * Drivers from the admin payload, the recommended one first, then the
 * bundled ones, then third-party drivers alphabetically.
 */
export function drivers(): DriverInfo[] {
  const payload = (app.data['fof-translate.drivers'] ?? {}) as DriverPayload;

  return Object.values(payload)
    .filter((driver): driver is DriverInfo => !!driver && typeof driver.name === 'string')
    .sort((a, b) => {
      const ia = order.indexOf(a.name);
      const ib = order.indexOf(b.name);

      if (ia !== -1 || ib !== -1) return (ia === -1 ? Infinity : ia) - (ib === -1 ? Infinity : ib);

      return a.name.localeCompare(b.name);
    });
}

export function findDriver(name: string | null | undefined): DriverInfo | undefined {
  return name ? drivers().find((driver) => driver.name === name) : undefined;
}
