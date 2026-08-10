package refine

import (
	"strings"
	"testing"

	"maniforge/internal/manifestengine/model"
)

func TestGenerateFromManifest(t *testing.T) {
	m := &model.Manifest{
		Code: "journey_note",
		Name: "Journey Note",
		Fields: []model.FieldDef{
			{Name: "title", Type: model.FieldString, Required: true},
			{Name: "body", Type: model.FieldString},
		},
	}
	sc, err := GenerateFromManifest(m, "http://127.0.0.1:8095/api/data")
	if err != nil {
		t.Fatal(err)
	}
	required := []string{
		"package.json",
		"src/App.tsx",
		"src/providers/manifestDataProvider.ts",
		"src/resources/journey_note.ts",
	}
	for _, path := range required {
		if sc.Files[path] == "" {
			t.Fatalf("missing %s", path)
		}
	}
	if !strings.Contains(sc.Files["src/providers/manifestDataProvider.ts"], "journey_note") {
		t.Fatal("data provider should reference entity code")
	}
}
