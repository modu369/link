package config

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"sync"
)

type Config struct {
	ListenHTTP         string   `json:"listen_http"`
	ListenHTTPS        string   `json:"listen_https"`
	AdminListen        string   `json:"admin_listen"`
	AdminUser          string   `json:"admin_user"`
	AdminPasswordHash  string   `json:"admin_password_hash"`
	WhitelistDomains   []string `json:"whitelist_domains"`
	CloudflareUpstream []string `json:"cloudflare_upstreams"`
	RoundRobin         bool     `json:"round_robin"`
	EnableHTTPProxy    bool     `json:"enable_http_proxy"`
}

type Store struct {
	path string
	mu   sync.RWMutex
	cfg  Config
}

func Default() Config {
	return Config{
		ListenHTTP:         ":80",
		ListenHTTPS:        ":443",
		AdminListen:        ":8080",
		AdminUser:          "admin",
		AdminPasswordHash:  "",
		WhitelistDomains:   []string{},
		CloudflareUpstream: []string{},
		RoundRobin:         true,
		EnableHTTPProxy:    true,
	}
}

func Load(path string) (*Store, error) {
	store := &Store{path: path, cfg: Default()}
	if err := store.read(); err != nil {
		if errors.Is(err, os.ErrNotExist) {
			if err := store.write(); err != nil {
				return nil, err
			}
			return store, nil
		}
		return nil, err
	}
	return store, nil
}

func (s *Store) read() error {
	data, err := os.ReadFile(s.path)
	if err != nil {
		return err
	}
	var cfg Config
	if err := json.Unmarshal(data, &cfg); err != nil {
		return err
	}
	s.cfg = normalize(cfg)
	return nil
}

func (s *Store) write() error {
	if err := os.MkdirAll(filepath.Dir(s.path), 0o755); err != nil {
		return err
	}
	data, err := json.MarshalIndent(s.cfg, "", "  ")
	if err != nil {
		return err
	}
	return os.WriteFile(s.path, data, 0o600)
}

func normalize(cfg Config) Config {
	cfg.AdminUser = strings.TrimSpace(cfg.AdminUser)
	cfg.ListenHTTP = strings.TrimSpace(cfg.ListenHTTP)
	cfg.ListenHTTPS = strings.TrimSpace(cfg.ListenHTTPS)
	cfg.AdminListen = strings.TrimSpace(cfg.AdminListen)
	cfg.WhitelistDomains = uniqueStrings(cfg.WhitelistDomains)
	cfg.CloudflareUpstream = uniqueStrings(cfg.CloudflareUpstream)
	if cfg.ListenHTTP == "" {
		cfg.ListenHTTP = ":80"
	}
	if cfg.ListenHTTPS == "" {
		cfg.ListenHTTPS = ":443"
	}
	if cfg.AdminListen == "" {
		cfg.AdminListen = ":8080"
	}
	return cfg
}

func uniqueStrings(values []string) []string {
	seen := make(map[string]struct{}, len(values))
	out := make([]string, 0, len(values))
	for _, value := range values {
		trimmed := strings.TrimSpace(strings.ToLower(value))
		if trimmed == "" {
			continue
		}
		if _, ok := seen[trimmed]; ok {
			continue
		}
		seen[trimmed] = struct{}{}
		out = append(out, trimmed)
	}
	return out
}

func (s *Store) Get() Config {
	s.mu.RLock()
	defer s.mu.RUnlock()
	return s.cfg
}

func (s *Store) Update(cfg Config) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.cfg = normalize(cfg)
	return s.write()
}
