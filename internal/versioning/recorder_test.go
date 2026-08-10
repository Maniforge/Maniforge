package versioning

import (
	"testing"

	"maniforge/internal/config"
)

func TestRedactSensitiveFields(t *testing.T) {
	in := map[string]any{
		"title": "ok", "phone": "+7900", "email": "a@b.c", "password": "secret",
	}
	out := redact(in)
	if out["title"] != "ok" {
		t.Fatal("title should remain")
	}
	if out["phone"] != "[redacted]" || out["email"] != "[redacted]" || out["password"] != "[redacted]" {
		t.Fatalf("expected redacted: %#v", out)
	}
}

func TestRecordSkipsWhenDisabled(t *testing.T) {
	r := &Recorder{cfg: config.Config{VersioningEnabled: false}}
	r.Record(Scope{TenantID: "t"}, TableManifestRecords, "1", "insert", nil, map[string]any{"x": 1}, "note")
	// no panic — disabled path
}
