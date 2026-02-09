package main

import (
	"context"
	"crypto/subtle"
	"embed"
	"encoding/json"
	"flag"
	"fmt"
	"log"
	"net"
	"net/http"
	"os"
	"os/signal"
	"strings"
	"sync"
	"sync/atomic"
	"syscall"
	"time"

	"cf-relay/internal/config"
	"cf-relay/internal/proxy"

	"golang.org/x/crypto/acme/autocert"
	"golang.org/x/crypto/bcrypt"
)

//go:embed web/admin.html
var adminFS embed.FS

type metrics struct {
	requests   uint64
	errors4xx  uint64
	errors5xx  uint64
	latencySum uint64
}

func main() {
	configPath := flag.String("config", "/etc/cf-relay/config.json", "config path")
	flag.Parse()

	store, err := config.Load(*configPath)
	if err != nil {
		log.Fatalf("load config: %v", err)
	}

	cfg := store.Get()
	if cfg.AdminPasswordHash == "" {
		log.Println("warning: admin_password_hash is empty; run admin setup")
	}

	pool := proxy.NewBackendPool(cfg.CloudflareUpstream)
	whitelist := proxy.NewWhitelist(cfg.WhitelistDomains)
	relay := proxy.NewProxy(pool, whitelist)

	metrics := &metrics{}

	proxyHandler := http.HandlerFunc(func(rw http.ResponseWriter, req *http.Request) {
		start := time.Now()
		wrapped := &statusWriter{ResponseWriter: rw}
		relay.Handler().ServeHTTP(wrapped, req)
		atomic.AddUint64(&metrics.requests, 1)
		statusCode := statusFromWriter(wrapped)
		if statusCode >= 400 && statusCode < 500 {
			atomic.AddUint64(&metrics.errors4xx, 1)
		} else if statusCode >= 500 {
			atomic.AddUint64(&metrics.errors5xx, 1)
		}
		atomic.AddUint64(&metrics.latencySum, uint64(time.Since(start).Milliseconds()))
	})

	autocertManager := autocert.Manager{
		Prompt:     autocert.AcceptTOS,
		Cache:      autocert.DirCache("/var/lib/cf-relay/autocert"),
		HostPolicy: hostPolicy(store),
	}

	manager := newServerManager(proxyHandler, &autocertManager)
	if err := manager.Start(cfg); err != nil {
		log.Fatalf("start listeners: %v", err)
	}

	adminServer := newAdminServer(store, relay, metrics, manager)

	adminHTTP := &http.Server{
		Addr:    cfg.AdminListen,
		Handler: adminServer,
	}

	go func() {
		if err := adminHTTP.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatalf("admin server: %v", err)
		}
	}()

	stop := make(chan os.Signal, 1)
	signal.Notify(stop, syscall.SIGINT, syscall.SIGTERM)
	<-stop

	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	_ = adminHTTP.Shutdown(ctx)
	_ = manager.Shutdown(ctx)
}

func hostPolicy(store *config.Store) autocert.HostPolicy {
	return func(ctx context.Context, host string) error {
		cfg := store.Get()
		host = strings.ToLower(strings.TrimSpace(host))
		for _, allowed := range cfg.WhitelistDomains {
			if host == allowed {
				return nil
			}
		}
		return fmt.Errorf("host not allowed")
	}
}

type statusWriter struct {
	http.ResponseWriter
	status int
}

func (w *statusWriter) WriteHeader(status int) {
	w.status = status
	w.ResponseWriter.WriteHeader(status)
}

func statusFromWriter(rw http.ResponseWriter) int {
	if sw, ok := rw.(*statusWriter); ok {
		if sw.status != 0 {
			return sw.status
		}
	}
	return http.StatusOK
}

type serverManager struct {
	mu         sync.Mutex
	handler    http.Handler
	autocert   *autocert.Manager
	httpSrv    *http.Server
	httpsSrv   *http.Server
	lastConfig config.Config
}

func newServerManager(handler http.Handler, autocertManager *autocert.Manager) *serverManager {
	return &serverManager{
		handler:  handler,
		autocert: autocertManager,
	}
}

func (m *serverManager) Start(cfg config.Config) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.lastConfig = cfg
	if err := m.startHTTP(cfg); err != nil {
		return err
	}
	if err := m.startHTTPS(cfg); err != nil {
		return err
	}
	return nil
}

func (m *serverManager) Update(oldCfg, newCfg config.Config) (bool, error) {
	needsRestart := oldCfg.ListenHTTP != newCfg.ListenHTTP ||
		oldCfg.ListenHTTPS != newCfg.ListenHTTPS ||
		oldCfg.EnableHTTPProxy != newCfg.EnableHTTPProxy

	if !needsRestart {
		return false, nil
	}

	m.mu.Lock()
	defer m.mu.Unlock()

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if err := m.shutdownLocked(ctx); err != nil {
		return false, err
	}
	m.lastConfig = newCfg
	if err := m.startHTTP(newCfg); err != nil {
		return false, err
	}
	if err := m.startHTTPS(newCfg); err != nil {
		return false, err
	}
	return true, nil
}

func (m *serverManager) Shutdown(ctx context.Context) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	return m.shutdownLocked(ctx)
}

func (m *serverManager) shutdownLocked(ctx context.Context) error {
	if m.httpSrv != nil {
		_ = m.httpSrv.Shutdown(ctx)
		m.httpSrv = nil
	}
	if m.httpsSrv != nil {
		_ = m.httpsSrv.Shutdown(ctx)
		m.httpsSrv = nil
	}
	return nil
}

func (m *serverManager) startHTTP(cfg config.Config) error {
	handler := http.HandlerFunc(func(rw http.ResponseWriter, req *http.Request) {
		if cfg.EnableHTTPProxy {
			m.handler.ServeHTTP(rw, req)
			return
		}
		http.Redirect(rw, req, "https://"+req.Host+req.URL.String(), http.StatusMovedPermanently)
	})

	server := &http.Server{
		Addr:    cfg.ListenHTTP,
		Handler: handler,
	}
	listener, err := net.Listen("tcp", cfg.ListenHTTP)
	if err != nil {
		return err
	}
	m.httpSrv = server
	go func() {
		if err := server.Serve(listener); err != nil && err != http.ErrServerClosed {
			log.Printf("http server: %v", err)
		}
	}()
	return nil
}

func (m *serverManager) startHTTPS(cfg config.Config) error {
	server := &http.Server{
		Addr:      cfg.ListenHTTPS,
		Handler:   m.handler,
		TLSConfig: m.autocert.TLSConfig(),
	}
	listener, err := net.Listen("tcp", cfg.ListenHTTPS)
	if err != nil {
		return err
	}
	m.httpsSrv = server
	go func() {
		if err := server.ServeTLS(listener, "", ""); err != nil && err != http.ErrServerClosed {
			log.Printf("https server: %v", err)
		}
	}()
	return nil
}

func newAdminServer(store *config.Store, relay *proxy.Proxy, metrics *metrics, manager *serverManager) http.Handler {
	mux := http.NewServeMux()

	mux.HandleFunc("/admin", func(rw http.ResponseWriter, req *http.Request) {
		if !requireAuth(store, rw, req) {
			return
		}
		data, err := adminFS.ReadFile("web/admin.html")
		if err != nil {
			http.Error(rw, "admin page missing", http.StatusInternalServerError)
			return
		}
		rw.Header().Set("Content-Type", "text/html; charset=utf-8")
		_, _ = rw.Write(data)
	})

	mux.HandleFunc("/admin/config", func(rw http.ResponseWriter, req *http.Request) {
		if !requireAuth(store, rw, req) {
			return
		}
		switch req.Method {
		case http.MethodGet:
			cfg := store.Get()
			response, _ := json.Marshal(cfg)
			rw.Header().Set("Content-Type", "application/json")
			_, _ = rw.Write(response)
		case http.MethodPost:
			var payload config.Config
			if err := json.NewDecoder(req.Body).Decode(&payload); err != nil {
				http.Error(rw, "invalid payload", http.StatusBadRequest)
				return
			}
			current := store.Get()
			if payload.ListenHTTP == "" {
				payload.ListenHTTP = current.ListenHTTP
			}
			if payload.ListenHTTPS == "" {
				payload.ListenHTTPS = current.ListenHTTPS
			}
			if payload.AdminListen == "" {
				payload.AdminListen = current.AdminListen
			}
			payload.RoundRobin = current.RoundRobin
			payload.AdminUser = current.AdminUser
			payload.AdminPasswordHash = current.AdminPasswordHash
			if err := store.Update(payload); err != nil {
				http.Error(rw, "failed to save", http.StatusInternalServerError)
				return
			}
			restarted, err := manager.Update(current, payload)
			if err != nil {
				http.Error(rw, "failed to apply", http.StatusInternalServerError)
				return
			}
			relay.UpdateBackends(payload.CloudflareUpstream)
			relay.UpdateWhitelist(payload.WhitelistDomains)
			response := map[string]any{
				"status":    "ok",
				"message":   "修改完成",
				"restarted": restarted,
			}
			data, _ := json.Marshal(response)
			rw.Header().Set("Content-Type", "application/json")
			_, _ = rw.Write(data)
		default:
			rw.WriteHeader(http.StatusMethodNotAllowed)
		}
	})

	mux.HandleFunc("/admin/admin", func(rw http.ResponseWriter, req *http.Request) {
		if !requireAuth(store, rw, req) {
			return
		}
		if req.Method != http.MethodPost {
			rw.WriteHeader(http.StatusMethodNotAllowed)
			return
		}
		var payload struct {
			AdminUser     string `json:"admin_user"`
			AdminPassword string `json:"admin_password"`
		}
		if err := json.NewDecoder(req.Body).Decode(&payload); err != nil {
			http.Error(rw, "invalid payload", http.StatusBadRequest)
			return
		}
		payload.AdminUser = strings.TrimSpace(payload.AdminUser)
		if payload.AdminUser == "" || payload.AdminPassword == "" {
			http.Error(rw, "missing credentials", http.StatusBadRequest)
			return
		}
		hash, err := bcrypt.GenerateFromPassword([]byte(payload.AdminPassword), bcrypt.DefaultCost)
		if err != nil {
			http.Error(rw, "failed to hash", http.StatusInternalServerError)
			return
		}
		cfg := store.Get()
		cfg.AdminUser = payload.AdminUser
		cfg.AdminPasswordHash = string(hash)
		if err := store.Update(cfg); err != nil {
			http.Error(rw, "failed to save", http.StatusInternalServerError)
			return
		}
		rw.WriteHeader(http.StatusNoContent)
	})

	mux.HandleFunc("/admin/status", func(rw http.ResponseWriter, req *http.Request) {
		if !requireAuth(store, rw, req) {
			return
		}
		requests := atomic.LoadUint64(&metrics.requests)
		latency := atomic.LoadUint64(&metrics.latencySum)
		avg := uint64(0)
		if requests > 0 {
			avg = latency / requests
		}
		payload := map[string]uint64{
			"requests":       requests,
			"errors_4xx":     atomic.LoadUint64(&metrics.errors4xx),
			"errors_5xx":     atomic.LoadUint64(&metrics.errors5xx),
			"avg_latency_ms": avg,
		}
		response, _ := json.Marshal(payload)
		rw.Header().Set("Content-Type", "application/json")
		_, _ = rw.Write(response)
	})

	return mux
}

func requireAuth(store *config.Store, rw http.ResponseWriter, req *http.Request) bool {
	cfg := store.Get()
	user, pass, ok := req.BasicAuth()
	if !ok {
		unauthorized(rw)
		return false
	}
	if subtle.ConstantTimeCompare([]byte(user), []byte(cfg.AdminUser)) != 1 {
		unauthorized(rw)
		return false
	}
	if cfg.AdminPasswordHash == "" {
		unauthorized(rw)
		return false
	}
	if err := bcrypt.CompareHashAndPassword([]byte(cfg.AdminPasswordHash), []byte(pass)); err != nil {
		unauthorized(rw)
		return false
	}
	return true
}

func unauthorized(rw http.ResponseWriter) {
	rw.Header().Set("WWW-Authenticate", "Basic realm=\"cf-relay\"")
	rw.WriteHeader(http.StatusUnauthorized)
}
