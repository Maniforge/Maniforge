package model

import "testing"

func TestOpenAPISpecFromFields(t *testing.T) {
	min := 0.0
	maxLen := 32
	m := &Manifest{
		Code: "invoice",
		Name: "Счёт",
		Fields: []FieldDef{
			{Name: "number", Type: FieldString, Required: true, MaxLength: &maxLen},
			{Name: "amount", Type: FieldNumber, Required: true, Min: &min},
			{Name: "paid", Type: FieldBoolean},
		},
	}
	spec := OpenAPISpec(m, "http://127.0.0.1:8095/api/data")
	paths, _ := spec["paths"].(map[string]any)
	if paths == nil {
		t.Fatal("no paths")
	}
	putPath, _ := paths["/api/data/invoice/{id}/number"].(map[string]any)
	if putPath == nil {
		t.Fatal("expected per-field PUT path")
	}
	postPath, _ := paths["/api/data/invoice"].(map[string]any)
	post, _ := postPath["post"].(map[string]any)
	rb, _ := post["requestBody"].(map[string]any)
	content, _ := rb["content"].(map[string]any)
	jsonCT, _ := content["application/json"].(map[string]any)
	schema, _ := jsonCT["schema"].(map[string]any)
	req, _ := schema["required"].([]string)
	if len(req) != 2 {
		t.Fatalf("required: %v", req)
	}
	props, _ := schema["properties"].(map[string]any)
	amount, _ := props["amount"].(map[string]any)
	if amount["minimum"] != 0.0 {
		t.Fatalf("amount min: %v", amount)
	}
	delPath, _ := paths["/api/data/invoice/{id}/number"].(map[string]any)
	if delPath["delete"] == nil {
		t.Fatal("expected per-field DELETE path")
	}
	nested, _ := paths["/api/data/invoice/{id}/{fieldPath}"].(map[string]any)
	if nested["delete"] == nil {
		t.Fatal("expected nested field DELETE")
	}
}
