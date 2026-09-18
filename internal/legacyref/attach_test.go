package legacyref

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestDeskServesRestoredApps(t *testing.T) {
	root := repoRoot(t)
	desk := filepath.Join(root, "deploy", "www-desk")
	for _, rel := range []string{
		"app/index.html",
		"scanner/index.html",
		"apps/index.html",
		"apps/devent/store.html",
		"apps/devent/wms.html",
		"apps/devent/index.html",
		"assets/css/site-nav.css",
		"assets/site-header.js",
		"about/index.html",
		"about-us/index.html",
	} {
		if _, err := os.Stat(filepath.Join(desk, filepath.FromSlash(rel))); err != nil {
			t.Errorf("desk missing attached app %s", rel)
		}
	}
}

func TestDeskNavigationLinksApps(t *testing.T) {
	root := repoRoot(t)
	header := readFile(t, filepath.Join(root, "frontend", "packages", "desk-ui", "src", "DeskHeader.tsx"))
	home := readFile(t, filepath.Join(root, "frontend", "apps", "desk", "src", "pages", "HomePage.tsx"))
	api := readFile(t, filepath.Join(root, "frontend", "apps", "desk", "src", "pages", "ApiDocsPage.tsx"))
	apps := readFile(t, filepath.Join(root, "deploy", "www-desk", "apps", "index.html"))

	for _, n := range []string{
		`/about/`, `/about-us/`, `/app/`, `/api/`, `/desk/login/`,
		`nav-cta`, `/desk/users/`, `/apps/`, `/scanner/`,
	} {
		if !strings.Contains(header, n) {
			t.Errorf("DeskHeader missing %s", n)
		}
	}
	for _, n := range []string{`href="/about/"`, `href="/api/"`, `href="/app/"`} {
		if !strings.Contains(home, n) {
			t.Errorf("HomePage missing %s", n)
		}
	}
	for _, n := range []string{`/warehouses`, `/products`, `/inventory`, `/wms`, `href="/about/"`} {
		if !strings.Contains(api, n) {
			t.Errorf("ApiDocsPage missing %s", n)
		}
	}
	for _, n := range []string{`href="/app/"`, `href="/scanner/"`, `href="/apps/devent/store.html"`, `href="/apps/devent/wms.html"`} {
		if !strings.Contains(apps, n) {
			t.Errorf("apps/index.html missing %s", n)
		}
	}
}
