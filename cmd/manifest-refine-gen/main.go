// Файл: main.go (cmd/manifest-refine-gen)
// Назначение: генерация Refine scaffold в templates/refine-manifest/generated/{code}/.
package main

import (
	"encoding/json"
	"flag"
	"fmt"
	"os"
	"path/filepath"
	"strings"

	"maniforge/internal/manifestengine/model"
	"maniforge/internal/manifestengine/refine"
)

func main() {
	outRoot := flag.String("out", "templates/refine-manifest/generated", "корень выходных файлов")
	apiBase := flag.String("api", "http://127.0.0.1:8095/api/data", "база Manifest data API")
	manifestFile := flag.String("manifest", "", "JSON manifest (code, name, fields)")
	flag.Parse()

	m, err := loadManifest(*manifestFile)
	if err != nil {
		fmt.Fprintf(os.Stderr, "manifest: %v\n", err)
		os.Exit(1)
	}

	sc, err := refine.GenerateFromManifest(m, *apiBase)
	if err != nil {
		fmt.Fprintf(os.Stderr, "generate: %v\n", err)
		os.Exit(1)
	}

	dir := filepath.Join(*outRoot, sc.EntityCode)
	for path, content := range sc.Files {
		full := filepath.Join(dir, path)
		if err := os.MkdirAll(filepath.Dir(full), 0o755); err != nil {
			fmt.Fprintf(os.Stderr, "mkdir: %v\n", err)
			os.Exit(1)
		}
		if err := os.WriteFile(full, []byte(content), 0o644); err != nil {
			fmt.Fprintf(os.Stderr, "write %s: %v\n", full, err)
			os.Exit(1)
		}
	}
	fmt.Printf("refine scaffold: %s (%d files)\n", dir, len(sc.Files))
}

func loadManifest(path string) (*model.Manifest, error) {
	if path == "" {
		return &model.Manifest{
			Code: "note",
			Name: "Note",
			Fields: []model.FieldDef{
				{Name: "title", Type: model.FieldString, Required: true, MaxLength: intPtr(200)},
				{Name: "body", Type: model.FieldString},
			},
		}, nil
	}
	raw, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	var in struct {
		Code   string           `json:"code"`
		Name   string           `json:"name"`
		Fields []model.FieldDef `json:"fields"`
	}
	if err := json.Unmarshal(raw, &in); err != nil {
		return nil, err
	}
	fields, err := model.ParseFieldDefs(in.Fields)
	if err != nil {
		return nil, err
	}
	if strings.TrimSpace(in.Code) == "" {
		return nil, fmt.Errorf("code обязателен")
	}
	return &model.Manifest{Code: in.Code, Name: in.Name, Fields: fields}, nil
}

func intPtr(n int) *int { return &n }
