package tenantid

import (
	"strings"
	"testing"
)

func TestFromUUIDIsStableAndPrefixed(t *testing.T) {
	const uuid = "550e8400-e29b-41d4-a716-446655440000"
	a := FromUUID(uuid)
	b := FromUUID(uuid)
	if a != b {
		t.Fatalf("FromUUID must be stable: %q vs %q", a, b)
	}
	if a != "t-a3a9e1ed9732cab2" {
		t.Fatalf("known UUID must hash to t-a3a9e1ed9732cab2, got %q", a)
	}
	if !strings.HasPrefix(a, "t-") {
		t.Fatalf("tenant id %q must start with t-", a)
	}
	hex := strings.TrimPrefix(a, "t-")
	if len(hex) != 16 {
		t.Fatalf("want 16 hex chars after t-, got %d (%q)", len(hex), a)
	}
	for _, r := range hex {
		if (r < '0' || r > '9') && (r < 'a' || r > 'f') {
			t.Fatalf("non-hex in tenant id: %q", a)
		}
	}
}

func TestFromUUIDDiffersPerUUID(t *testing.T) {
	a := FromUUID("11111111-1111-4111-8111-111111111111")
	b := FromUUID("22222222-2222-4222-8222-222222222222")
	if a == b {
		t.Fatal("different UUIDs must not share tenant id")
	}
}

func TestNewLooksLikeHashedUUID(t *testing.T) {
	id, err := New()
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasPrefix(id, "t-") || len(id) != 18 {
		t.Fatalf("New()=%q want t- + 16 hex", id)
	}
	other, err := New()
	if err != nil {
		t.Fatal(err)
	}
	if id == other {
		t.Fatal("two New() calls must not collide")
	}
}
