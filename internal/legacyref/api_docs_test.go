package legacyref

import (
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestApiDocsPhpCatalogModulesSurviveOnDesk(t *testing.T) {
	root := repoRoot(t)
	php := readFile(t, filepath.Join(root, "templates", "data", "api-docs", "catalog.php"))
	catPath := filepath.Join(root, "deploy", "www-desk", "assets", "api-docs-catalog.json")
	raw, err := os.ReadFile(catPath)
	if err != nil {
		t.Fatalf("catalog json: %v", err)
	}
	var cat map[string]any
	if err := json.Unmarshal(raw, &cat); err != nil {
		t.Fatalf("catalog json parse: %v", err)
	}
	docs, _ := cat["docs"].(map[string]any)
	for _, key := range []string{
		"credentials", "headers", "public", "private", "manifest",
		"versioning", "realtime", "warehouses", "products", "inventory", "wms",
	} {
		if !strings.Contains(php, "'key' => '"+key+"'") {
			t.Fatalf("php catalog missing %s", key)
		}
		if docs[key] == nil {
			t.Errorf("catalog json missing docs.%s", key)
		}
	}
	blob := string(raw)
	for _, label := range []string{
		"Ключи", "Заголовки", "RBAC", "Tenant Licensing", "Manifest Engine",
		"WMS", "С чего начать", "MF_HEADER_",
	} {
		if !strings.Contains(blob, label) {
			t.Errorf("catalog json lost %q", label)
		}
	}
}

func TestApiDocsDeskKeepsLiveSwaggerAndSupplyDocs(t *testing.T) {
	src := readFile(t, filepath.Join(repoRoot(t), "frontend", "apps", "desk", "src", "pages", "ApiDocsPage.tsx"))
	for _, n := range []string{
		`/api/rbac/`,
		`/api/tenant-licensing/`,
		`/api/manifest/`,
		`/about/`,
	} {
		if !strings.Contains(src, n) {
			t.Errorf("ApiDocsPage missing live/docs link %s", n)
		}
	}
}

func TestApiDocsUxChecklistMatchesPhpAndDeskTheme(t *testing.T) {
	root := repoRoot(t)
	page := readFile(t, filepath.Join(root, "frontend", "apps", "desk", "src", "pages", "ApiDocsPage.tsx"))
	css := readFile(t, filepath.Join(root, "frontend", "apps", "desk", "src", "styles", "api-docs.css"))
	header := readFile(t, filepath.Join(root, "frontend", "packages", "desk-ui", "src", "DeskHeader.tsx"))

	type check struct {
		item   string
		hay    string
		needle string
	}
	checks := []check{
		{"1 compact dock", page, `api-dock`},
		{"1 columns", page, `api-layout`},
		{"1 footer", page, `api-dock-footer`},
		{"2 headers accordion", page, `api-profile-details`},
		{"3 hover scrollbar", css, `.api-content:hover`},
		{"4 last tab", page, `api-docs-tab`},
		{"4 all sections", page, `api-docs-tabs-expanded`},
		{"5 header chip", page, `api-header-symbol`},
		{"6 breadcrumbs", page, `api-breadcrumbs`},
		{"7 search", page, `api-docs-search`},
		{"7 ctrl+k", page, `Ctrl+K`},
		{"8 collapsible hero", page, `api-page-hero`},
		{"8 hero storage", page, `api-docs-hero-collapsed`},
		{"9 start here", page, `С чего начать`},
		{"10 headers mini-toc", page, `api-headers-toc`},
		{"11 MF_HEADER symbols", page, `MF_HEADER_`},
		{"12 copy profile", page, `data-api-copy`},
		{"13 auth flow", page, `api-cred-flow`},
		{"14 fields to POST", page, `data-api-field-link`},
		{"15 desk bearer", page, `maniforge_access_token`},
		{"16 scroll-spy", page, `data-api-spy-section`},
		{"17 mobile tabs", page, `api-mobile-tabs`},
		{"18 search a11y", page, `api-docs-search`},
		{"19 print css", css, `@media print`},
		{"20 desk accent", css, `#7aa2ff`},
		{"20 desk bg", css, `#0c0d10`},
		{"site header", header, `Mani<i>forge</i>`},
	}
	for _, c := range checks {
		if !strings.Contains(c.hay, c.needle) {
			t.Errorf("UX %s: missing %q", c.item, c.needle)
		}
	}
}

func TestApiDocsSectionsReadLikePhpCards(t *testing.T) {
	root := repoRoot(t)
	page := readFile(t, filepath.Join(root, "frontend", "apps", "desk", "src", "pages", "ApiDocsPage.tsx"))
	css := readFile(t, filepath.Join(root, "frontend", "apps", "desk", "src", "styles", "api-docs.css"))
	for _, n := range []string{
		"Параметры URL",
		"Тело запроса",
		"Ответы",
		"Доступ при вызове",
		"Когда нужен",
		"Как получить",
		"Как передать",
	} {
		if !strings.Contains(page, n) {
			t.Errorf("section card missing %q", n)
		}
	}
	for _, n := range []string{
		`.api-tabs-group:not(.is-current)`,
		`.api-group + .api-group`,
		`.api-spec-label`,
		`.api-method-card`,
		`overflow-y: auto`,
		`#root:has(.api-page)`,
	} {
		if !strings.Contains(css, n) {
			t.Errorf("section css missing %q", n)
		}
	}
}
