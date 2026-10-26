export type DriverFieldType = 'string' | 'password' | 'url' | 'number' | 'select' | 'switch';

export interface DriverField {
  type: DriverFieldType;
  required?: boolean;
  options?: string[] | Record<string, string>;
  envOverride?: boolean;
}

export interface DriverInfo {
  name: string;
  configured: boolean;
  fields: Record<string, DriverField>;
}

export type DriverPayload = Record<string, DriverInfo>;
