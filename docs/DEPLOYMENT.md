# 新系统部署教程（多 Worker）

## 1. 初始化数据库

```bash
mysql -u your_user -p your_db < schema.sql
```

## 2. 配置环境变量

建议通过 `.env` 或系统环境变量配置：

```ini
INGEST_MODE=queue
ROLLUP_ONLY=true
RETENTION_DAYS=180
RETENTION_PAGEVIEWS_DAYS=60
INGEST_QUEUE_MAX=100000
INGEST_AUTO_DRAIN=true
INGEST_AUTO_DRAIN_EVERY=50
INGEST_AUTO_DRAIN_BATCH=200
```

## 3. 启动 ingest worker（写入原始表）

常驻：

```bash
php /path/to/project/cli/ingest_worker.php --loop --max=1000 --sleep=1
```

## 4. 启动 rollup worker（聚合统计）

单实例：

```bash
php /path/to/project/cli/rollup_worker.php --loop --sleep=10 --hours=2
```

多实例（按 site_id 分片）示例：

```bash
php /path/to/project/cli/rollup_worker.php --loop --workers=4 --worker=1
php /path/to/project/cli/rollup_worker.php --loop --workers=4 --worker=2
php /path/to/project/cli/rollup_worker.php --loop --workers=4 --worker=3
php /path/to/project/cli/rollup_worker.php --loop --workers=4 --worker=4
```

## 5. 配置清理任务（可选）

```bash
php /path/to/project/cli/cleanup_retention.php
```

## 6. 验证点

- `/track.php` 是否写入 Redis 队列（`LLEN` 检查）。
- ingest worker 是否把队列写入 `pageviews`。
- rollup worker 是否生成 `pageview_rollups` / `pageview_dimension_rollups`。
- 后台页面是否仅读取 rollup（无 `pageviews` 扫描）。

## 7. 常见问题

**Q: 页面为空？**  
Rollup 还未生成，等待 worker 汇总后刷新。

**Q: 统计延迟？**  
调整 rollup worker 频率或缩短 `--hours`/`sleep`。
