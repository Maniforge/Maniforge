package channel

import "testing"

func TestSuggestAll(t *testing.T) {
	ch := SuggestAll(map[string]string{"invoice": "custom", "product": "platform"})
	if len(ch) < 5 {
		t.Fatalf("expected meta + per-entity channels, got %v", ch)
	}
	if ch[0] != EntityAll || ch[1] != EntityCustom || ch[2] != EntityPlatform {
		t.Fatalf("meta order: %v", ch)
	}
}

func TestValidateClientChannel(t *testing.T) {
	if !ValidateClientChannel("data.invoice") || !ValidateClientChannel(EntityCustom) || !ValidateClientChannel(EntityPlatform) {
		t.Fatal("valid channels")
	}
	if ValidateClientChannel("manifest") {
		t.Fatal("legacy manifest channel removed")
	}
}

func TestValidateAgainstManifests(t *testing.T) {
	m := map[string]string{"invoice": "custom", "product": "platform"}
	if err := ValidateAgainstManifests("data.invoice", m); err != nil {
		t.Fatal(err)
	}
	if err := ValidateAgainstManifests("data.unknown", m); err == nil {
		t.Fatal("expected error for unknown entity")
	}
	if err := ValidateAgainstManifests(EntityPlatform, m); err != nil {
		t.Fatal(err)
	}
}

func TestMatchesEvent(t *testing.T) {
	if !MatchesEvent(EntityAll, "data.invoice", "custom") {
		t.Fatal("entity.all should match data channels")
	}
	if MatchesEvent(EntityCustom, "data.product", "platform") {
		t.Fatal("entity.custom should not match platform")
	}
	if !MatchesEvent(EntityPlatform, "data.product", "platform") {
		t.Fatal("entity.platform should match platform data")
	}
	if !MatchesEvent("data.invoice", "data.invoice", "custom") {
		t.Fatal("exact channel match")
	}
}
