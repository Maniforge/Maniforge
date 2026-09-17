export type ApiAction = {
  label: string;
  href: string;
  tab?: string;
};

export type ApiModule = {
  key: string;
  section_id: string;
  nav_label: string;
  nav_purpose: string;
  badge: string;
  badge_label: string;
  common_nav_label: string;
  is_reference?: boolean;
  reference_panel?: string;
  actions?: ApiAction[];
};

export type ApiCategory = {
  id: string;
  title: string;
  description: string;
  modules: ApiModule[];
};

export type ApiHeaderRow = {
  name: string;
  required?: boolean | string;
  description?: string;
  scope?: string;
};

export type ApiProfile = {
  id: string;
  label: string;
  note?: string;
  headers?: ApiHeaderRow[];
};

export type ApiField = {
  name: string;
  type?: string;
  required?: boolean;
  description?: string;
};

export type ApiEndpoint = {
  id?: string;
  method?: string;
  path?: string;
  title?: string;
  summary?: string;
  auth?: string;
  headers_profile?: string;
  headers_prefix?: string;
  headers_extra?: ApiHeaderRow[];
  query?: ApiField[];
  body?: {
    content_type?: string;
    fields?: ApiField[];
    example?: string;
  } | null;
  responses?: { code?: number; description?: string; example?: string }[];
};

export type ApiGroup = {
  id: string;
  title: string;
  generated?: boolean;
  manifest_code?: string;
  is_live_panel?: boolean;
  is_fields_panel?: boolean;
  endpoints?: ApiEndpoint[];
};

export type ApiDoc = {
  title?: string;
  description?: string;
  actions?: ApiAction[];
  overview?: {
    levels?: { title: string; badge: string; summary: string; anchor: string }[];
    flow?: { title: string; text: string }[];
    paragraphs?: string[];
    headers?: ApiHeaderRow[];
  };
  sections?: {
    id: string;
    title: string;
    profile_prefix?: string;
    tokens?: {
      name: string;
      label?: string;
      when?: string;
      how_get?: string;
      how_send?: string;
      lifetime?: string;
      header_prefix?: string;
      header_profile?: string;
    }[];
    profiles?: ApiProfile[];
  }[];
  common?: {
    access?: { title?: string; paragraphs?: string[]; links?: ApiAction[] };
    errors?: { code?: number; description?: string; example?: string }[];
    header_profiles?: ApiProfile[];
  };
  groups?: ApiGroup[];
};

export type ApiCatalog = {
  default_module_key?: string;
  categories: ApiCategory[];
  references?: { label: string; href: string }[];
  docs: Record<string, ApiDoc>;
};

const SYMBOLS: Record<string, string> = {
  none: 'MF_HEADER_NIL',
  tenant_login: 'MF_HEADER_TENANT_LOGIN',
  refresh: 'MF_HEADER_REFRESH',
  bearer: 'MF_HEADER_BEARER',
  bearer_csrf: 'MF_HEADER_BEARER_CSRF',
  bearer_action_csrf: 'MF_HEADER_BEARER_ACTION_CSRF',
  platform: 'MF_HEADER_PLATFORM',
  internal: 'MF_HEADER_INTERNAL',
};

export function profileSymbol(profileId: string): string {
  if (SYMBOLS[profileId]) return SYMBOLS[profileId];
  return 'MF_HEADER_' + profileId.replace(/[^a-z0-9]+/gi, '_').replace(/^_|_$/g, '').toUpperCase();
}

function copyValue(name: string, profileId: string): string {
  if (name === 'Authorization') {
    if (profileId === 'platform') return 'Bearer {TENANT_LICENSING_ADMIN_TOKEN}';
    if (profileId === 'internal') return 'Bearer {TENANT_LICENSING_INTERNAL_TOKEN}';
    return 'Bearer {access_token}';
  }
  switch (name) {
    case 'X-CSRF-Token':
      return '{csrf_token}';
    case 'X-Action-Token':
      return '{action_token}';
    case 'X-Tenant-ID':
      return '{tenant_id}';
    case 'X-Subtenant-ID':
      return '{subtenant_id}';
    case 'Content-Type':
    case 'Accept':
      return 'application/json';
    default:
      return '...';
  }
}

export function profileCopyBlock(profile: ApiProfile): string {
  const headers: Record<string, string> = {};
  for (const row of profile.headers || []) {
    if (!row.name) continue;
    headers[row.name] = copyValue(row.name, profile.id);
  }
  return Object.keys(headers).length ? JSON.stringify(headers, null, 2) : '';
}

export function findProfile(catalog: ApiCatalog, prefix: string, profileId: string): ApiProfile | undefined {
  const headersDoc = catalog.docs.headers;
  for (const section of headersDoc?.sections || []) {
    if (section.profile_prefix !== prefix) continue;
    const found = (section.profiles || []).find((p) => p.id === profileId);
    if (found) return found;
  }
  return undefined;
}

export function headersPrefixForModule(key: string): string {
  if (key === 'public') return 'rbac';
  if (key === 'private') return 'platform';
  return 'modules';
}

export function deskAccessToken(): string {
  try {
    return localStorage.getItem('maniforge_access_token') || localStorage.getItem('maniforge_admin_access_token') || '';
  } catch {
    return '';
  }
}
