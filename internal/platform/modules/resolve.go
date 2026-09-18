package modules

import (
	"fmt"
	"os"
	"sort"
	"strings"
)

// Resolved is the apply-set for one MANIFORGE_MODULES value.
type Resolved struct {
	Packages        []string
	Services        []Service
	HealthPaths     []string
	DirectHealth    []string
	CaddyHandles    []Service
	SystemdEnable   []string
	SystemdDisable  []string
	ComposeProfiles []string
	ComposeServices []string
}

func Resolve(cat Catalog, modulesSpec string) (Resolved, error) {
	selected, err := expandPackages(cat, modulesSpec)
	if err != nil {
		return Resolved{}, err
	}
	for name, pkg := range cat.Packages {
		if pkg.Always {
			selected[name] = true
		}
	}
	for name := range selected {
		pkg, ok := cat.Packages[name]
		if !ok {
			return Resolved{}, fmt.Errorf("unknown package %q", name)
		}
		for _, req := range pkg.Requires {
			if !selected[req] && !cat.Packages[req].Always {
				return Resolved{}, fmt.Errorf("%s requires %s (add %s to MANIFORGE_MODULES or remove %s)", name, req, req, name)
			}
		}
	}

	allSystemd := map[string]bool{}
	for _, pkg := range cat.Packages {
		for _, svc := range pkg.Services {
			if svc.Systemd != "" {
				allSystemd[svc.Systemd] = true
			}
		}
	}

	var out Resolved
	seenSvc := map[string]bool{}
	seenSystemd := map[string]bool{}

	for _, name := range packageOrder(cat, selected) {
		pkg := cat.Packages[name]
		out.Packages = append(out.Packages, name)
		if pkg.ComposeProfile != "" {
			out.ComposeProfiles = append(out.ComposeProfiles, pkg.ComposeProfile)
		}
		for _, svc := range pkg.Services {
			if seenSvc[svc.ID] {
				continue
			}
			seenSvc[svc.ID] = true
			out.Services = append(out.Services, svc)
			if svc.Compose != "" {
				out.ComposeServices = append(out.ComposeServices, svc.Compose)
			}
			if svc.HealthPath != "" {
				out.HealthPaths = append(out.HealthPaths, svc.HealthPath)
			}
			if svc.DirectHealth != "" {
				out.DirectHealth = append(out.DirectHealth, svc.DirectHealth)
			}
			if len(svc.CaddyPaths) > 0 {
				out.CaddyHandles = append(out.CaddyHandles, svc)
			}
			if svc.Systemd != "" && !seenSystemd[svc.Systemd] {
				seenSystemd[svc.Systemd] = true
				out.SystemdEnable = append(out.SystemdEnable, svc.Systemd)
			}
		}
	}

	for u := range allSystemd {
		if !seenSystemd[u] {
			out.SystemdDisable = append(out.SystemdDisable, u)
		}
	}
	sort.Strings(out.SystemdDisable)
	return out, nil
}

func packageOrder(cat Catalog, selected map[string]bool) []string {
	prefer := []string{"core", "versioning", "realtime", "supply", "wms"}
	var out []string
	seen := map[string]bool{}
	for _, name := range prefer {
		if selected[name] {
			out = append(out, name)
			seen[name] = true
		}
	}
	var rest []string
	for name := range selected {
		if !seen[name] {
			if _, ok := cat.Packages[name]; ok {
				rest = append(rest, name)
			}
		}
	}
	sort.Strings(rest)
	return append(out, rest...)
}

func expandPackages(cat Catalog, spec string) (map[string]bool, error) {
	spec = strings.TrimSpace(spec)
	if spec == "" {
		spec = strings.TrimSpace(cat.Default)
	}
	if spec == "" {
		spec = "full"
	}
	selected := map[string]bool{}
	for _, part := range strings.Split(spec, ",") {
		name := strings.ToLower(strings.TrimSpace(part))
		if name == "" {
			continue
		}
		if aliases, ok := cat.Aliases[name]; ok {
			for _, a := range aliases {
				selected[a] = true
			}
			continue
		}
		if _, ok := cat.Packages[name]; !ok {
			return nil, fmt.Errorf("unknown package %q", name)
		}
		selected[name] = true
	}
	if len(selected) == 0 {
		return nil, fmt.Errorf("MANIFORGE_MODULES is empty")
	}
	return selected, nil
}

func ReadModulesSpec(envFile string) string {
	if v := strings.TrimSpace(os.Getenv("MANIFORGE_MODULES")); v != "" {
		return v
	}
	if envFile == "" {
		return ""
	}
	raw, err := os.ReadFile(envFile)
	if err != nil {
		return ""
	}
	for _, line := range strings.Split(string(raw), "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		key, val, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}
		if strings.TrimSpace(key) == "MANIFORGE_MODULES" {
			return strings.Trim(strings.TrimSpace(val), `"'`)
		}
	}
	return ""
}

func ShellExport(r Resolved) string {
	var b strings.Builder
	assign := func(key, val string) {
		fmt.Fprintf(&b, "%s='%s'\n", key, strings.ReplaceAll(val, `'`, `'"'"'`))
	}
	assign("MANIFORGE_RESOLVED_PACKAGES", strings.Join(r.Packages, ","))
	assign("MANIFORGE_COMPOSE_PROFILES", strings.Join(r.ComposeProfiles, ","))
	assign("MANIFORGE_COMPOSE_SERVICES", strings.Join(r.ComposeServices, " "))
	assign("MANIFORGE_SYSTEMD_ENABLE", strings.Join(r.SystemdEnable, " "))
	assign("MANIFORGE_SYSTEMD_DISABLE", strings.Join(r.SystemdDisable, " "))
	assign("MANIFORGE_HEALTH_PATHS", strings.Join(r.HealthPaths, " "))
	assign("MANIFORGE_DIRECT_HEALTH", strings.Join(r.DirectHealth, " "))
	return b.String()
}
