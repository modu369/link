# Cloudflare Relay

面向中国大陆优化的 Cloudflare 中转反向代理系统，支持域名白名单、真实 IP 透传、HTTP/HTTPS 双端口、优选 IP 负载均衡与可视化管理后台。

## 功能特性

- **域名白名单**：仅允许授权域名通过代理，防止被滥用。
- **真实 IP 透传**：自动注入 `X-Forwarded-For` / `X-Forwarded-Proto`。
- **HTTP/HTTPS 支持**：80/443 同时监听，HTTPS 使用 Let’s Encrypt 自动签发证书。
- **负载均衡**：对多组 Cloudflare 优选 IP 轮询转发。
- **连接复用**：使用长连接池，减少握手开销。
- **可视化后台**：支持配置白名单、优选 IP 与账号密码。

## 安装（Debian 12）

```bash
sudo ./scripts/install.sh
```

安装完成后访问：

```
http://<server_ip>:8080/admin
```

首次安装请在后台设置管理员密码，并配置白名单域名与 Cloudflare 优选 IP。

## 卸载

```bash
sudo ./scripts/uninstall.sh
```

## 配置说明

配置文件默认路径：`/etc/cf-relay/config.json`

```json
{
  "listen_http": ":80",
  "listen_https": ":443",
  "admin_listen": ":8080",
  "admin_user": "admin",
  "admin_password_hash": "",
  "whitelist_domains": ["example.com"],
  "cloudflare_upstreams": ["104.16.0.1:443", "104.16.0.2:443"],
  "round_robin": true,
  "enable_http_proxy": true
}
```

> 注意：修改监听地址或 HTTP 转发开关会自动重启监听并返回“修改完成”，所有配置即时生效，无需手动重启。

## 运行方式

```bash
cf-relay -config /etc/cf-relay/config.json
```
