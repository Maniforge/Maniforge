package model

import "testing"

func TestNormalizeDocSlug(t *testing.T) {
	if got := NormalizeDocSlug("  Sales "); got != "sales" {
		t.Fatalf("got %q", got)
	}
	if NormalizeDocSlug("") != "" {
		t.Fatal("empty")
	}
}

func TestApplyDocCatalogMetadata(t *testing.T) {
	tPtr := "finance"
	sPtr := "sales"
	meta := ApplyDocCatalogMetadata(map[string]any{"foo": "bar"}, &tPtr, &sPtr)
	if meta["type"] != "finance" || meta["section"] != "sales" || meta["foo"] != "bar" {
		t.Fatalf("%v", meta)
	}
	empty := ""
	meta = ApplyDocCatalogMetadata(meta, &empty, &empty)
	if _, ok := meta["type"]; ok {
		t.Fatal("type should be removed")
	}
	if _, ok := meta["section"]; ok {
		t.Fatal("section should be removed")
	}
}
