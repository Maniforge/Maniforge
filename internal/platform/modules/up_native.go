package modules

import (
	"fmt"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

func NativeBin(root, name string) string {
	p := filepath.Join(root, "bin", name)
	if runtime.GOOS == "windows" {
		if _, err := os.Stat(p + ".exe"); err == nil {
			return p + ".exe"
		}
	}
	return p
}

func AddrPort(addr string) string {
	addr = strings.TrimSpace(addr)
	if addr == "" {
		return ""
	}
	if strings.HasPrefix(addr, ":") {
		return "127.0.0.1" + addr
	}
	return addr
}

func PortListening(addr string) bool {
	host := AddrPort(addr)
	if host == "" {
		return false
	}
	c, err := net.DialTimeout("tcp", host, 400*time.Millisecond)
	if err != nil {
		return false
	}
	_ = c.Close()
	return true
}

func StartNative(root string, r Resolved, envFile string) error {
	for _, svc := range r.Services {
		if svc.Binary == "" || svc.Binary == "caddy" {
			continue
		}
		if svc.DefaultAddr != "" && PortListening(svc.DefaultAddr) {
			continue
		}
		bin := NativeBin(root, svc.Binary)
		if _, err := os.Stat(bin); err != nil {
			return fmt.Errorf("%s: %w", bin, err)
		}
		cmd := exec.Command(bin)
		cmd.Dir = root
		cmd.Env = os.Environ()
		logDir := filepath.Join(root, "bin", "native-gw")
		_ = os.MkdirAll(logDir, 0o755)
		out, err := os.OpenFile(filepath.Join(logDir, svc.ID+".log"), os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o644)
		if err == nil {
			cmd.Stdout = out
			cmd.Stderr = out
		}
		if err := cmd.Start(); err != nil {
			return fmt.Errorf("start %s: %w", svc.Binary, err)
		}
	}

	caddyListen := ":18090"
	if !PortListening(caddyListen) {
		caddy := NativeBin(root, "caddy")
		cfg := filepath.Join(root, "deploy", "Caddyfile.active")
		if _, err := os.Stat(caddy); err != nil {
			return fmt.Errorf("caddy: %w", err)
		}
		cmd := exec.Command(caddy, "run", "--config", cfg, "--adapter", "caddyfile")
		cmd.Dir = root
		logDir := filepath.Join(root, "bin", "native-gw")
		_ = os.MkdirAll(logDir, 0o755)
		out, err := os.OpenFile(filepath.Join(logDir, "caddy.log"), os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o644)
		if err == nil {
			cmd.Stdout = out
			cmd.Stderr = out
		}
		if err := cmd.Start(); err != nil {
			return fmt.Errorf("start caddy: %w", err)
		}
	}
	_ = envFile
	return nil
}
