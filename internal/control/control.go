package control

import (
	"bytes"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"strings"
	"time"
)

type Agent struct {
	Name    string `json:"name"`
	Address string `json:"address"`
	Token   string `json:"token"`
}

type ConfigPayload struct {
	ListenHTTP         string   `json:"listen_http"`
	ListenHTTPS        string   `json:"listen_https"`
	WhitelistDomains   []string `json:"whitelist_domains"`
	CloudflareUpstream []string `json:"cloudflare_upstreams"`
	EnableHTTPProxy    bool     `json:"enable_http_proxy"`
}

type ApplyResponse struct {
	Status    string `json:"status"`
	Message   string `json:"message"`
	Restarted bool   `json:"restarted"`
}

func ApplyConfig(agent Agent, payload ConfigPayload) (ApplyResponse, error) {
	client := &http.Client{Timeout: 10 * time.Second}
	body, err := json.Marshal(payload)
	if err != nil {
		return ApplyResponse{}, err
	}
	req, err := http.NewRequest(http.MethodPost, strings.TrimRight(agent.Address, "/")+"/agent/config", bytes.NewReader(body))
	if err != nil {
		return ApplyResponse{}, err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Authorization", "Bearer "+agent.Token)
	resp, err := client.Do(req)
	if err != nil {
		return ApplyResponse{}, err
	}
	defer resp.Body.Close()
	if resp.StatusCode >= 300 {
		msg, _ := io.ReadAll(resp.Body)
		return ApplyResponse{}, errors.New(string(msg))
	}
	var out ApplyResponse
	if err := json.NewDecoder(resp.Body).Decode(&out); err != nil {
		return ApplyResponse{}, err
	}
	return out, nil
}
