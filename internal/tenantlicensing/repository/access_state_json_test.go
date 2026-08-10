package repository

import (
	"encoding/json"
	"testing"
)

func TestAccessState_JSONIncludesSubtenantActiveFalse(t *testing.T) {
	raw, err := json.Marshal(AccessState{
		OK:              true,
		TenantCode:      "t1",
		ProjectCode:     "main",
		TenantActive:    true,
		ProjectActive:   false,
		LicenseActive:   true,
		Features:        map[string]any{},
		Limits:          map[string]any{},
		CheckedAt:       "2026-01-01 00:00:00",
		SubtenantCode:   "main",
		SubtenantActive: false,
	})
	if err != nil {
		t.Fatalf("marshal: %v", err)
	}
	var out map[string]any
	if err := json.Unmarshal(raw, &out); err != nil {
		t.Fatalf("unmarshal: %v", err)
	}
	v, ok := out["subtenant_active"]
	if !ok {
		t.Fatal("subtenant_active must be present")
	}
	b, ok := v.(bool)
	if !ok || b {
		t.Fatalf("subtenant_active must be false, got: %v", v)
	}
}
