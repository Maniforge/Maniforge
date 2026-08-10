package model

import "testing"

func TestParseFieldDefsArrayItems(t *testing.T) {
	fields, err := ParseFieldDefs([]FieldDef{
		{Name: "tags", Type: FieldArray, Items: &FieldDef{Type: FieldString}},
		{Name: "meta", Type: FieldObject},
	})
	if err != nil {
		t.Fatal(err)
	}
	if fields[0].Items == nil || fields[0].Items.Type != FieldString {
		t.Fatalf("items: %#v", fields[0].Items)
	}
}

func TestParseFieldDefsEmptyName(t *testing.T) {
	_, err := ParseFieldDefs([]FieldDef{{Name: "  ", Type: FieldString}})
	if err == nil {
		t.Fatal("expected empty name error")
	}
}

func TestRecordSchemaRequired(t *testing.T) {
	schema := recordSchema([]FieldDef{
		{Name: "a", Type: FieldString, Required: true},
		{Name: "b", Type: FieldNumber},
	})
	req, _ := schema["required"].([]string)
	if len(req) != 1 || req[0] != "a" {
		t.Fatalf("required: %v", req)
	}
	props, _ := schema["properties"].(map[string]any)
	if props["b"] == nil {
		t.Fatal("missing property b")
	}
}

func TestFieldToSchemaAllTypes(t *testing.T) {
	maxLen := 10
	min := 1.0
	max := 99.0
	cases := []struct {
		def  FieldDef
		typ  string
		keys []string
	}{
		{FieldDef{Type: FieldString, MaxLength: &maxLen}, "string", []string{"maxLength"}},
		{FieldDef{Type: FieldNumber, Min: &min, Max: &max}, "number", []string{"minimum", "maximum"}},
		{FieldDef{Type: FieldBoolean}, "boolean", nil},
		{FieldDef{Type: FieldArray, Items: &FieldDef{Type: FieldString}}, "array", nil},
		{FieldDef{Type: FieldObject}, "object", nil},
	}
	for _, tc := range cases {
		s := fieldToSchema(tc.def)
		if s["type"] != tc.typ {
			t.Fatalf("%s type: %v", tc.typ, s)
		}
		for _, k := range tc.keys {
			if s[k] == nil {
				t.Fatalf("%s missing %s", tc.typ, k)
			}
		}
	}
}
