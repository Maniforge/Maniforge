package model

import "testing"

func TestAssertClientMayDefineManifest(t *testing.T) {
	reserved := func(code string) bool { return code == "product" }
	if err := AssertClientMayDefineManifest("product", nil, reserved); err == nil {
		t.Fatal("reserved code")
	}
	if err := AssertClientMayDefineManifest("note", map[string]any{"preset": "x"}, reserved); err == nil {
		t.Fatal("preset metadata")
	}
	if err := AssertClientMayDefineManifest("note", nil, reserved); err != nil {
		t.Fatal(err)
	}
}

func TestAssertClientMayMutateManifest(t *testing.T) {
	if err := AssertClientMayMutateManifest(&Manifest{Origin: OriginPlatform}); err == nil {
		t.Fatal("platform immutable")
	}
	if err := AssertClientMayMutateManifest(&Manifest{Origin: OriginCustom}); err != nil {
		t.Fatal(err)
	}
}
