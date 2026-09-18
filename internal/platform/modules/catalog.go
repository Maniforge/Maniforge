package modules

import (
	"fmt"
	"os"
	"path/filepath"

	"gopkg.in/yaml.v3"
)

// Catalog is deploy/modules.yaml — package SKUs and process metadata.
type Catalog struct {
	Default  string              `yaml:"default"`
	Aliases  map[string][]string `yaml:"aliases"`
	Packages map[string]Package  `yaml:"packages"`
}

type Package struct {
	Always         bool      `yaml:"always"`
	Requires       []string  `yaml:"requires"`
	ComposeProfile string    `yaml:"compose_profile"`
	Services       []Service `yaml:"services"`
}

type Service struct {
	ID              string   `yaml:"id"`
	Compose         string   `yaml:"compose"`
	Systemd         string   `yaml:"systemd"`
	Binary          string   `yaml:"binary"`
	DefaultAddr     string   `yaml:"default_addr"`
	HealthPath      string   `yaml:"health_path"`
	DirectHealth    string   `yaml:"direct_health"`
	CaddyName       string   `yaml:"caddy_name"`
	CaddyPaths      []string `yaml:"caddy_paths"`
	ComposeUpstream string   `yaml:"compose_upstream"`
	HostUpstream    string   `yaml:"host_upstream"`
}

func LoadCatalog(path string) (Catalog, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		return Catalog{}, err
	}
	var cat Catalog
	if err := yaml.Unmarshal(raw, &cat); err != nil {
		return Catalog{}, fmt.Errorf("modules.yaml: %w", err)
	}
	if len(cat.Packages) == 0 {
		return Catalog{}, fmt.Errorf("modules.yaml: no packages")
	}
	return cat, nil
}

func CatalogPath(root string) string {
	return filepath.Join(root, "deploy", "modules.yaml")
}
