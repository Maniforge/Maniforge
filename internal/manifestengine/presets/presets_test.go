package presets

import "testing"

func TestByCode(t *testing.T) {
	if _, ok := ByCode("product"); !ok {
		t.Fatal("product")
	}
	if _, ok := ByCode("stock"); !ok {
		t.Fatal("stock")
	}
	if _, ok := ByCode("unknown"); ok {
		t.Fatal("unknown should fail")
	}
}

func TestProductFields(t *testing.T) {
	p := Product()
	if p.Code != "product" || len(p.Fields) < 4 {
		t.Fatalf("product preset: %#v", p)
	}
}
