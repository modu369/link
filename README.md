# Polymarket BTC 实时监控系统

该项目提供基于 PHP 8.2 + MySQL 8.0 的 Polymarket 比特币实时监控与自动交易规则管理界面。

## 功能概览
- 实时显示当前轮次开盘/封盘时间、开盘价（Price to Beat）、现价、UP/DOWN 价格。
- Polymarket 账户管理（添加/删除/状态显示）。
- 每个账户独立的交易规则配置与自动止损设置。
- 交易记录可视化追踪。

> 交易执行部分目前通过 `PolymarketClient` 预留接口，实际对接 Polymarket API 时请替换逻辑。

## 安装与运行
1. 创建数据库并导入结构：

```bash
mysql -u root -p -e "CREATE DATABASE polymarket DEFAULT CHARACTER SET utf8mb4"
mysql -u root -p polymarket < sql/schema.sql
```

> 已有数据库需要补充字段：

```sql
ALTER TABLE rounds ADD COLUMN external_key VARCHAR(120) NOT NULL;
CREATE UNIQUE INDEX rounds_external_key_unique ON rounds (external_key);
```

2. 配置环境变量（示例）：

```bash
export DB_DSN="mysql:host=127.0.0.1;dbname=polymarket;charset=utf8mb4"
export DB_USER="root"
export DB_PASSWORD="password"

# Polymarket 配置
export POLYMARKET_EVENT_SLUG="btc-updown-15m-1766628900"
export POLYMARKET_EVENT_URL="https://polymarket.com/api/event/%s"
```

3. 启动 PHP 服务：

```bash
php -S 0.0.0.0:8080 -t public
```

4. 可选：启用规则执行器（建议 cron 每分钟运行一次）：

```bash
php bin/worker.php
```

## 实时数据说明
- 默认调用 Polymarket 官方事件接口，如被限制可替换 `POLYMARKET_EVENT_URL`。
- 前端每 1 秒刷新一次数据，并禁用缓存，保证价格与时间同步。
