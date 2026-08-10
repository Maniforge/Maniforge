package model

import "testing"

func TestParseRecordFilter(t *testing.T) {
	f, err := ParseRecordFilter(`{"title":"hello%"}`)
	if err != nil || f["title"] != "hello%" {
		t.Fatalf("parse: %v %#v", err, f)
	}
	if _, err := ParseRecordFilter("{"); err == nil {
		t.Fatal("expected json error")
	}
}

func TestValidateFilterKeys(t *testing.T) {
	fields := []FieldDef{{Name: "title", Type: FieldString}}
	if err := ValidateFilterKeys(fields, RecordFilter{"title": "a"}); err != nil {
		t.Fatal(err)
	}
	if err := ValidateFilterKeys(fields, RecordFilter{"secret": "a"}); err == nil {
		t.Fatal("unknown field should fail")
	}
}

func TestIsLikePattern(t *testing.T) {
	if _, ok := IsLikePattern("hello%"); !ok {
		t.Fatal("expected like")
	}
	if _, ok := IsLikePattern("hello"); ok {
		t.Fatal("expected exact")
	}
}
