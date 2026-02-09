package proxy

import (
	"errors"
	"net"
	"net/http"
	"net/http/httputil"
	"net/url"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

type BackendPool struct {
	backends []string
	index    uint64
}

func NewBackendPool(backends []string) *BackendPool {
	return &BackendPool{backends: backends}
}

func (p *BackendPool) Next() (string, error) {
	if len(p.backends) == 0 {
		return "", errors.New("no backends configured")
	}
	idx := atomic.AddUint64(&p.index, 1)
	return p.backends[int(idx)%len(p.backends)], nil
}

type Whitelist struct {
	mu      sync.RWMutex
	domains map[string]struct{}
}

func NewWhitelist(domains []string) *Whitelist {
	w := &Whitelist{domains: make(map[string]struct{}, len(domains))}
	w.Update(domains)
	return w
}

func (w *Whitelist) Update(domains []string) {
	w.mu.Lock()
	defer w.mu.Unlock()
	w.domains = make(map[string]struct{}, len(domains))
	for _, domain := range domains {
		trimmed := strings.TrimSpace(strings.ToLower(domain))
		if trimmed == "" {
			continue
		}
		w.domains[trimmed] = struct{}{}
	}
}

func (w *Whitelist) Allowed(host string) bool {
	if host == "" {
		return false
	}
	host = strings.ToLower(host)
	if strings.Contains(host, ":") {
		if splitHost, _, err := net.SplitHostPort(host); err == nil {
			host = splitHost
		}
	}
	w.mu.RLock()
	defer w.mu.RUnlock()
	_, ok := w.domains[host]
	return ok
}

type Proxy struct {
	mu        sync.RWMutex
	transport *http.Transport
	pool      *BackendPool
	whitelist *Whitelist
}

func NewProxy(pool *BackendPool, whitelist *Whitelist) *Proxy {
	transport := &http.Transport{
		Proxy:                 http.ProxyFromEnvironment,
		DialContext:           (&net.Dialer{Timeout: 5 * time.Second, KeepAlive: 30 * time.Second}).DialContext,
		ForceAttemptHTTP2:     true,
		MaxIdleConns:          256,
		MaxIdleConnsPerHost:   64,
		IdleConnTimeout:       90 * time.Second,
		TLSHandshakeTimeout:   10 * time.Second,
		ExpectContinueTimeout: 1 * time.Second,
	}
	return &Proxy{transport: transport, pool: pool, whitelist: whitelist}
}

func (p *Proxy) Handler() http.Handler {
	reverse := &httputil.ReverseProxy{
		Director: func(req *http.Request) {
			backend, err := p.nextBackend()
			if err != nil {
				return
			}
			incomingScheme := "http"
			if req.TLS != nil {
				incomingScheme = "https"
			}
			target := &url.URL{Scheme: incomingScheme, Host: backend}
			req.URL.Scheme = target.Scheme
			req.URL.Host = target.Host
			req.Host = req.Host
			clientIP, _, err := net.SplitHostPort(req.RemoteAddr)
			if err == nil {
				xff := req.Header.Get("X-Forwarded-For")
				if xff == "" {
					req.Header.Set("X-Forwarded-For", clientIP)
				} else {
					req.Header.Set("X-Forwarded-For", xff+", "+clientIP)
				}
			}
			req.Header.Set("X-Forwarded-Proto", incomingScheme)
		},
		Transport: p.transport,
		ErrorHandler: func(rw http.ResponseWriter, req *http.Request, err error) {
			http.Error(rw, "upstream unavailable", http.StatusBadGateway)
		},
		ModifyResponse: func(resp *http.Response) error {
			return nil
		},
	}

	return http.HandlerFunc(func(rw http.ResponseWriter, req *http.Request) {
		if !p.hasBackends() {
			rw.WriteHeader(http.StatusServiceUnavailable)
			_, _ = rw.Write([]byte("no backends available"))
			return
		}
		if !p.whitelist.Allowed(req.Host) {
			rw.WriteHeader(http.StatusForbidden)
			_, _ = rw.Write([]byte("domain not allowed"))
			return
		}
		reverse.ServeHTTP(rw, req)
	})
}

func (p *Proxy) UpdateBackends(backends []string) {
	p.mu.Lock()
	defer p.mu.Unlock()
	p.pool = NewBackendPool(backends)
}

func (p *Proxy) UpdateWhitelist(domains []string) {
	p.whitelist.Update(domains)
}

func (p *Proxy) hasBackends() bool {
	p.mu.RLock()
	defer p.mu.RUnlock()
	return p.pool != nil && len(p.pool.backends) > 0
}

func (p *Proxy) nextBackend() (string, error) {
	p.mu.RLock()
	pool := p.pool
	p.mu.RUnlock()
	if pool == nil {
		return "", errors.New("no backends configured")
	}
	return pool.Next()
}
