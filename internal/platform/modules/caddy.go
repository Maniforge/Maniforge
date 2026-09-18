package modules

import (
	"fmt"
	"strings"
)

type CaddyOpts struct {
	Listen   string // ":8080", ":18090", or hostname for TLS site
	Mode     string // compose | host
	Fallback string
	WebRoot  string // host mode: deploy/www-desk; empty keeps a text fallback
}

func RenderCaddy(r Resolved, opts CaddyOpts) string {
	listen := strings.TrimSpace(opts.Listen)
	if listen == "" {
		if opts.Mode == "compose" {
			listen = ":8080"
		} else {
			listen = ":18090"
		}
	}
	fallback := opts.Fallback
	if fallback == "" {
		if opts.Mode == "compose" {
			fallback = "Maniforge platform gateway"
		} else if strings.HasPrefix(listen, ":") {
			fallback = "Maniforge platform (nzgapp)"
		} else {
			fallback = "Maniforge platform"
		}
	}
	webRoot := strings.ReplaceAll(strings.TrimSpace(opts.WebRoot), `\`, `/`)

	var b strings.Builder
	fmt.Fprintf(&b, "%s {\n", listen)
	for _, svc := range r.CaddyHandles {
		up := svc.HostUpstream
		if opts.Mode == "compose" {
			up = svc.ComposeUpstream
		}
		if up == "" || svc.CaddyName == "" || len(svc.CaddyPaths) == 0 {
			continue
		}
		fmt.Fprintf(&b, "	@%s path %s\n", svc.CaddyName, strings.Join(svc.CaddyPaths, " "))
		fmt.Fprintf(&b, "	handle @%s {\n", svc.CaddyName)
		fmt.Fprintf(&b, "		reverse_proxy %s\n", up)
		fmt.Fprintf(&b, "	}\n\n")
	}
	fmt.Fprintf(&b, "	handle {\n")
	if webRoot != "" {
		fmt.Fprintf(&b, "		root * %s\n", webRoot)
		fmt.Fprintf(&b, "		encode gzip\n")
		fmt.Fprintf(&b, "		try_files {path} {path}/ {path}/index.html\n")
		fmt.Fprintf(&b, "		file_server\n")
	} else {
		fmt.Fprintf(&b, "		respond %q 200\n", fallback)
	}
	fmt.Fprintf(&b, "	}\n")
	fmt.Fprintf(&b, "}\n")
	return b.String()
}

func HasCaddyHandle(r Resolved, pathNeedle string) bool {
	for _, svc := range r.CaddyHandles {
		for _, p := range svc.CaddyPaths {
			if p == pathNeedle {
				return true
			}
		}
	}
	return false
}

func HasSystemd(r Resolved, unit string) bool {
	for _, u := range r.SystemdEnable {
		if u == unit {
			return true
		}
	}
	return false
}
