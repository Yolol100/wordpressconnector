PRAGMA foreign_keys = ON;

-- No WordPress credentials, ChatGPT tokens, or raw pair secrets are stored.
CREATE TABLE IF NOT EXISTS central_sites (
  id TEXT PRIMARY KEY NOT NULL,
  tenant_id TEXT NOT NULL,
  canonical_origin TEXT NOT NULL,
  label TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL CHECK(status IN ('pending', 'active', 'suspended')),
  origin_verified INTEGER NOT NULL DEFAULT 0 CHECK(origin_verified IN (0, 1)),
  verified_at TEXT,
  revoked_at TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE(tenant_id, canonical_origin),
  UNIQUE(id, tenant_id)
);

CREATE TABLE IF NOT EXISTS central_site_grants (
  site_id TEXT NOT NULL,
  tenant_id TEXT NOT NULL,
  subject TEXT NOT NULL,
  read_granted INTEGER NOT NULL DEFAULT 0 CHECK(read_granted IN (0, 1)),
  write_granted INTEGER NOT NULL DEFAULT 0 CHECK(write_granted IN (0, 1)),
  granted_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at TEXT,
  PRIMARY KEY(site_id, subject),
  FOREIGN KEY(site_id, tenant_id) REFERENCES central_sites(id, tenant_id)
);

CREATE INDEX IF NOT EXISTS idx_sites_tenant ON central_sites(tenant_id, status, revoked_at);
CREATE INDEX IF NOT EXISTS idx_grants_subject ON central_site_grants(tenant_id, subject, revoked_at);
