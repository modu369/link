# 系统优化实施说明（按 7 步）

本文档对应新的高并发统计架构，确保**数据精准**的同时降低 CPU 与内存占用。每一步均包含**已优化内容**与对应实现位置。

## 第 1 步：采集写入路径拆分（原始写入 + 异步汇总）

**已优化内容**
- 采集入口只写入原始事件（`pageviews`），不再在请求线程执行维度/汇总写入。
- 维度与汇总由异步 worker 批量生成，降低请求高峰 CPU 波动。

**对应实现**
- 采集入口：`public/track.php`
- 原始写入：`src/Tracker.php::processPageview`
- Rollup 不再在请求线程执行（移除 `updateRollups` 调用）。

---

## 第 2 步：精准 rollup 聚合（分钟/小时批量）

**已优化内容**
- 使用 `site_id + bucket_start`（小时粒度）作为 rollup 分区键。
- 通过 worker 按小时聚合，严格 `COUNT(DISTINCT ip_hash)` 统计 UV/IP。
- Session 相关指标通过会话去重计算（`MAX(duration)` / `MAX(page_count)`）。

**对应实现**
- Rollup worker：`cli/rollup_worker.php`
- 汇总表结构：`schema.sql`

---

## 第 3 步：查询路径强制走 rollup

**已优化内容**
- 所有统计页不再访问 `pageviews`，当 rollup 不完整时返回空数据（提示“汇总中”）。
- 前端顶部展示汇总提示。

**对应实现**
- Rollup-only 模式：`config/config.php` → `rollup_only`
- 运行期开关：`src/Tracker.php::$rollupOnly`
- 前端提示：`public/layout.php`

---

## 第 4 步：数据库结构重建（高并发友好）

**已优化内容**
- 原始表 `pageviews` 只保留高并发所需字段。
- Rollup 表拆分为主汇总、维度汇总、受访页、入口页等。
- 分区策略：`PARTITION BY HASH(site_id)`。

**对应实现**
- 新 schema：`schema.sql`

---

## 第 5 步：多 Worker 架构（高吞吐）

**已优化内容**
- Ingest worker：从 Redis 队列写入 `pageviews`。
- Rollup worker：按小时批量聚合生成统计结果。
- 支持多实例分片（`--worker` / `--workers`）。

**对应实现**
- Ingest worker：`cli/ingest_worker.php`
- Rollup worker：`cli/rollup_worker.php`

---

## 第 6 步：CPU/内存抖动控制

**已优化内容**
- 请求线程不再聚合统计。
- 降低 auto-drain 频率，写入压力分散到 worker。
- Rollup 仅处理新增窗口，避免全量扫描。

**对应实现**
- 队列配置：`config/config.php` → `ingest` 配置项
- Worker 循环 sleep 控制：`cli/ingest_worker.php` / `cli/rollup_worker.php`

---

## 第 7 步：部署与说明

请查看 `docs/DEPLOYMENT.md`。
