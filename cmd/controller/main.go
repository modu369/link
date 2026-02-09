package main

import (
	"context"
	"crypto/subtle"
	"embed"
	"encoding/json"
	"flag"
	"log"
	"net/http"
	"os"
	"os/signal"
	"path/filepath"
	"strings"
	"syscall"
	"time"

	"cf-relay/internal/config"
	"cf-relay/internal/control"

	"golang.org/x/crypto/bcrypt"
)

//go:embed web/admin.html
var adminFS embed.FS

type controllerState struct {
	agents map[string]control.Agent
}

func main() {
	configPath := flag.String("config", "/etc/cf-relay/controller.json", "config path")
	agentsPath := flag.String("agents", "/etc/cf-relay/agents.json", "agents config path")
	listen := flag.String("listen", ":8080", "controller listen")
	flag.Parse()

	store, err := config.Load(*configPath)
	if err != nil {
		log.Fatalf("load config: %v", err)
	}
	cfg := store.Get()

	state := &controllerState{
		agents: make(map[string]control.Agent),
	}
	if err := loadAgents(*agentsPath, state); err != nil {
		log.Printf("load agents: %v", err)
	}

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

	mux.HandleFunc("/controller/agents", func(rw http.ResponseWriter, req *http.Request) {
		if !requireAuth(store, rw, req) {
			return
		}
		switch req.Method {
		case http.MethodGet:
			response, _ := json.Marshal(state.agents)
			rw.Header().Set("Content-Type", "application/json")
			_, _ = rw.Write(response)
		case http.MethodPost:
			var payload control.Agent
			if err := json.NewDecoder(req.Body).Decode(&payload); err != nil {
				http.Error(rw, "invalid payload", http.StatusBadRequest)
				return
			}
			payload.Name = strings.TrimSpace(payload.Name)
			payload.Address = strings.TrimSpace(payload.Address)
			if payload.Name == "" || payload.Address == "" || payload.Token == "" {
				http.Error(rw, "missing agent fields", http.StatusBadRequest)
				return
			}
			state.agents[payload.Name] = payload
			if err := saveAgents(*agentsPath, state); err != nil {
				http.Error(rw, "failed to save agent", http.StatusInternalServerError)
				return
			}
			rw.WriteHeader(http.StatusNoContent)
		default:
			rw.WriteHeader(http.StatusMethodNotAllowed)
		}
	})

	mux.HandleFunc("/controller/apply", func(rw http.ResponseWriter, req *http.Request) {
		if !requireAuth(store, rw, req) {
			return
		}
		if req.Method != http.MethodPost {
			rw.WriteHeader(http.StatusMethodNotAllowed)
			return
		}
		var payload struct {
			Agent  string                `json:"agent"`
			Config control.ConfigPayload `json:"config"`
		}
		if err := json.NewDecoder(req.Body).Decode(&payload); err != nil {
			http.Error(rw, "invalid payload", http.StatusBadRequest)
			return
		}
		agent, ok := state.agents[payload.Agent]
		if !ok {
			http.Error(rw, "agent not found", http.StatusNotFound)
			return
		}
		result, err := control.ApplyConfig(agent, payload.Config)
		if err != nil {
			http.Error(rw, err.Error(), http.StatusBadGateway)
			return
		}
		response, _ := json.Marshal(result)
		rw.Header().Set("Content-Type", "application/json")
		_, _ = rw.Write(response)
	})

	mux.HandleFunc("/controller/admin", func(rw http.ResponseWriter, req *http.Request) {
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

	server := &http.Server{
		Addr:    *listen,
		Handler: mux,
	}

	go func() {
		if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatalf("controller server: %v", err)
		}
	}()

	stop := make(chan os.Signal, 1)
	signal.Notify(stop, syscall.SIGINT, syscall.SIGTERM)
	<-stop

	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	_ = server.Shutdown(ctx)
	_ = cfg
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

func loadAgents(path string, state *controllerState) error {
	data, err := os.ReadFile(path)
	if err != nil {
		if os.IsNotExist(err) {
			return nil
		}
		return err
	}
	var agents map[string]control.Agent
	if err := json.Unmarshal(data, &agents); err != nil {
		return err
	}
	state.agents = agents
	return nil
}

func saveAgents(path string, state *controllerState) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return err
	}
	data, err := json.MarshalIndent(state.agents, "", "  ")
	if err != nil {
		return err
	}
	return os.WriteFile(path, data, 0o600)
}
