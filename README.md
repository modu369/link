# Polymarket BTC 实时监控系统

该项目提供基于 PHP 8.2 + MySQL 8.0 的 Polymarket 比特币实时监控与自动交易规则管理界面。

## 功能概览
- 实时显示当前轮次开盘/封盘时间、开盘价、现价、UP/DOWN 位置。
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

2. 配置环境变量（示例）：

```bash
export DB_DSN="mysql:host=127.0.0.1;dbname=polymarket;charset=utf8mb4"
export DB_USER="root"
export DB_PASSWORD="password"
```

3. 启动 PHP 服务：

```bash
php -S 0.0.0.0:8080 -t public
```

4. 可选：启用规则执行器（建议 cron 每分钟运行一次）：

```bash
php bin/worker.php
```

## 价格数据源
默认使用 Coinbase BTC-USD 现价接口，可通过 `PRICE_PROVIDER_URL` 覆盖。
