CREATE TABLE IF NOT EXISTS trips (
  id INTEGER PRIMARY KEY,
  edit_token_hash TEXT NOT NULL UNIQUE,
  view_token_hash TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  base_currency TEXT NOT NULL,
  version INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL,
  last_active_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS trip_rates (
  trip_id INTEGER NOT NULL REFERENCES trips(id),
  currency TEXT NOT NULL,
  rate_e8 INTEGER NOT NULL,
  PRIMARY KEY (trip_id, currency)
);

CREATE TABLE IF NOT EXISTS members (
  id INTEGER PRIMARY KEY,
  trip_id INTEGER NOT NULL REFERENCES trips(id),
  display_name TEXT NOT NULL,
  sort_order INTEGER NOT NULL,
  archived INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS expenses (
  id INTEGER PRIMARY KEY,
  trip_id INTEGER NOT NULL REFERENCES trips(id),
  description TEXT NOT NULL,
  spent_on TEXT NOT NULL,
  payer_id INTEGER NOT NULL REFERENCES members(id),
  amount_minor INTEGER NOT NULL,
  currency TEXT NOT NULL,
  rate_e8 INTEGER NOT NULL,
  base_minor INTEGER NOT NULL,
  split_mode TEXT NOT NULL DEFAULT 'equal',
  created_by INTEGER REFERENCES members(id),
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  deleted_at TEXT,
  version INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS shares (
  expense_id INTEGER NOT NULL REFERENCES expenses(id),
  member_id INTEGER NOT NULL REFERENCES members(id),
  weight INTEGER NOT NULL DEFAULT 1,
  share_minor INTEGER NOT NULL,
  PRIMARY KEY (expense_id, member_id)
);

CREATE TABLE IF NOT EXISTS settlements (
  id INTEGER PRIMARY KEY,
  trip_id INTEGER NOT NULL REFERENCES trips(id),
  from_id INTEGER NOT NULL REFERENCES members(id),
  to_id INTEGER NOT NULL REFERENCES members(id),
  amount_minor INTEGER NOT NULL,
  settled_on TEXT NOT NULL,
  deleted_at TEXT
);

CREATE TABLE IF NOT EXISTS audit_log (
  id INTEGER PRIMARY KEY,
  trip_id INTEGER NOT NULL,
  action TEXT NOT NULL,
  payload_json TEXT NOT NULL,
  at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS rate_limits (
  bucket TEXT NOT NULL,
  window_start INTEGER NOT NULL,
  hits INTEGER NOT NULL,
  PRIMARY KEY (bucket, window_start)
);

CREATE INDEX IF NOT EXISTS idx_trips_last_active ON trips(last_active_at);
CREATE INDEX IF NOT EXISTS idx_members_trip ON members(trip_id, sort_order);
CREATE INDEX IF NOT EXISTS idx_expenses_trip ON expenses(trip_id, deleted_at);
CREATE INDEX IF NOT EXISTS idx_expenses_payer ON expenses(payer_id);
CREATE INDEX IF NOT EXISTS idx_shares_member ON shares(member_id);
CREATE INDEX IF NOT EXISTS idx_settlements_trip ON settlements(trip_id);
CREATE INDEX IF NOT EXISTS idx_audit_trip ON audit_log(trip_id, at);
CREATE INDEX IF NOT EXISTS idx_rate_limits_window ON rate_limits(window_start);
