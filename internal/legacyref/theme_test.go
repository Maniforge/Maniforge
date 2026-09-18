package legacyref

import (
	"path/filepath"
	"strings"
	"testing"
)

func TestSpaCssMatchesDeskTokens(t *testing.T) {
	root := repoRoot(t)
	needles := []string{"#0c0d10", "#7aa2ff", "IBM Plex Sans", "#14161c", "#f2f3f5"}
	files := []string{
		"frontend/apps/admin/src/styles/app.css",
		"frontend/apps/scanner/src/styles/scanner.css",
		"public/assets/css/site-nav.css",
		"public/assets/css/desk-spa.css",
		"deploy/www-desk/assets/css/desk-spa.css",
		"deploy/www-desk/app/index.html",
		"deploy/www-desk/scanner/index.html",
	}
	for _, rel := range files {
		body := readFile(t, filepath.Join(root, filepath.FromSlash(rel)))
		if strings.Contains(rel, "index.html") {
			if !strings.Contains(body, "/assets/css/desk-spa.css") {
				t.Errorf("%s must load desk-spa.css", rel)
			}
			if !strings.Contains(body, "fonts.css") {
				t.Errorf("%s must load IBM Plex fonts", rel)
			}
			continue
		}
		for _, n := range needles {
			if !strings.Contains(body, n) {
				t.Errorf("%s missing desk token %s", rel, n)
			}
		}
	}
}
