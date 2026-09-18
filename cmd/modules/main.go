package main

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"

	"maniforge/internal/platform/modules"
)

func main() {
	args := os.Args[1:]
	cmd := "resolve"
	if len(args) > 0 && !strings.HasPrefix(args[0], "-") {
		cmd = args[0]
		args = args[1:]
	}
	root, envFile, format, mode, listen, out, spec := parseFlags(args)
	if root == "" {
		wd, _ := os.Getwd()
		root = wd
	}
	if envFile == "" {
		envFile = filepath.Join(root, "deploy", ".env.platform")
	}
	cat, err := modules.LoadCatalog(modules.CatalogPath(root))
	if err != nil {
		fail(err)
	}
	if spec == "" {
		spec = modules.ReadModulesSpec(envFile)
	}
	resolved, err := modules.Resolve(cat, spec)
	if err != nil {
		fail(err)
	}

	switch cmd {
	case "resolve":
		if format == "json" {
			enc := json.NewEncoder(os.Stdout)
			enc.SetIndent("", "  ")
			_ = enc.Encode(resolved)
			return
		}
		fmt.Print(modules.ShellExport(resolved))
	case "caddy":
		if mode == "" {
			mode = "host"
		}
		body := modules.RenderCaddy(resolved, modules.CaddyOpts{Mode: mode, Listen: listen})
		if out == "" {
			fmt.Print(body)
			return
		}
		if err := os.WriteFile(out, []byte(body), 0o644); err != nil {
			fail(err)
		}
	case "up-native":
		if mode == "" {
			mode = "host"
		}
		if listen == "" {
			listen = ":18090"
		}
		active := filepath.Join(root, "deploy", "Caddyfile.active")
		body := modules.RenderCaddy(resolved, modules.CaddyOpts{Mode: "host", Listen: listen})
		if err := os.WriteFile(active, []byte(body), 0o644); err != nil {
			fail(err)
		}
		if err := modules.StartNative(root, resolved, envFile); err != nil {
			fail(err)
		}
		fmt.Print(modules.ShellExport(resolved))
	default:
		fail(fmt.Errorf("unknown command %q (resolve|caddy|up-native)", cmd))
	}
}

func parseFlags(args []string) (root, env, format, mode, listen, out, spec string) {
	format = "shell"
	for i := 0; i < len(args); i++ {
		a := args[i]
		next := func() string {
			i++
			if i >= len(args) {
				fail(fmt.Errorf("missing value for %s", a))
			}
			return args[i]
		}
		switch a {
		case "--root":
			root = next()
		case "--env":
			env = next()
		case "--format":
			format = next()
		case "--mode":
			mode = next()
		case "--listen":
			listen = next()
		case "-o", "--out":
			out = next()
		case "--modules":
			spec = next()
		default:
			fail(fmt.Errorf("unknown flag %s", a))
		}
	}
	return
}

func fail(err error) {
	fmt.Fprintf(os.Stderr, "maniforge-modules: %v\n", err)
	os.Exit(1)
}
