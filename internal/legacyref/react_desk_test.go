package legacyref

import (
	"path/filepath"
	"strings"
	"testing"
)

func TestReactDeskHeaderIsSharedAcrossApps(t *testing.T) {
	root := repoRoot(t)
	header := readFile(t, filepath.Join(root, "frontend", "packages", "desk-ui", "src", "DeskHeader.tsx"))
	for _, n := range []string{
		`className="top"`,
		`Mani<i>forge</i>`,
		`/about/`,
		`/about-us/`,
		`/app/`,
		`/api/`,
		`/desk/login/`,
		`/desk/users/`,
		`/scanner/`,
		`maniforge_access_token`,
	} {
		if !strings.Contains(header, n) {
			t.Errorf("DeskHeader missing %s", n)
		}
	}
	for _, rel := range []string{
		"frontend/apps/admin/src/components/AppShell.tsx",
		"frontend/apps/scanner/src/components/ScannerShell.tsx",
		"frontend/apps/desk/src/App.tsx",
	} {
		body := readFile(t, filepath.Join(root, filepath.FromSlash(rel)))
		if !strings.Contains(body, "DeskHeader") {
			t.Errorf("%s must use shared DeskHeader", rel)
		}
	}
}

func TestReactDeskServesApiDocsRoute(t *testing.T) {
	root := repoRoot(t)
	router := readFile(t, filepath.Join(root, "frontend", "apps", "desk", "src", "App.tsx"))
	for _, n := range []string{`path="/api"`, `ApiDocsPage`, `path="/desk/login"`} {
		if !strings.Contains(router, n) {
			t.Errorf("desk App missing %s", n)
		}
	}
}
