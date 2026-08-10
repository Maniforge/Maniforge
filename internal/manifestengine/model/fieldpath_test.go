package model

import "testing"

func TestSetFieldPathNested(t *testing.T) {
	root := map[string]any{
		"variants": []any{
			map[string]any{"price": 10},
		},
	}
	if err := SetFieldPath(root, "variants/0/price", 99); err != nil {
		t.Fatal(err)
	}
	v, err := GetFieldPath(root, "variants/0/price")
	if err != nil || v != 99 {
		t.Fatalf("got %v err %v", v, err)
	}
}

func TestClearFieldPathSetsNull(t *testing.T) {
	root := map[string]any{"body": "text", "note": "x"}
	if err := ClearFieldPath(root, "body"); err != nil {
		t.Fatal(err)
	}
	if root["body"] != nil {
		t.Fatalf("body: %#v", root["body"])
	}
	if root["note"] != "x" {
		t.Fatalf("note changed: %#v", root["note"])
	}
}

func TestClearFieldPathNested(t *testing.T) {
	root := map[string]any{
		"variants": []any{map[string]any{"price": 10, "sku": "a"}},
	}
	if err := ClearFieldPath(root, "variants/0/price"); err != nil {
		t.Fatal(err)
	}
	v, err := GetFieldPath(root, "variants/0/price")
	if err != nil || v != nil {
		t.Fatalf("price: %v err %v", v, err)
	}
	sku, _ := GetFieldPath(root, "variants/0/sku")
	if sku != "a" {
		t.Fatalf("sku: %#v", sku)
	}
}

func TestValidateDataRequired(t *testing.T) {
	fields := []FieldDef{{Name: "title", Type: FieldString, Required: true}}
	err := ValidateData(fields, map[string]any{}, false)
	if err == nil {
		t.Fatal("expected required error")
	}
}
