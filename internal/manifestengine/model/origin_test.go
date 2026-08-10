package model

import "testing"

func TestNormalizeOrigin(t *testing.T) {
	o, ok := NormalizeOrigin("platform")
	if !ok || o != OriginPlatform {
		t.Fatal("platform")
	}
	o, ok = NormalizeOrigin("custom")
	if !ok || o != OriginCustom {
		t.Fatal("custom")
	}
	_, ok = NormalizeOrigin("bad")
	if ok {
		t.Fatal("bad should fail")
	}
}
