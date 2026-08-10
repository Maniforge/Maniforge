/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_RBAC_BASE: string;
  readonly VITE_MANIFEST_BASE: string;
  readonly VITE_REALTIME_BASE: string;
  readonly VITE_WAREHOUSES_BASE: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
