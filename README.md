# 网站访客统计（PHP + Nginx + MySQL + Redis）

一个简化版的 51LA / CNZZ / 百度统计风格访客统计系统，提供可嵌入 JS 脚本的埋点、像素上报接口以及后台数据概览页。后端使用 PHP，MySQL 存储，Redis 用于站点缓存和 UV 去重，并带有蜘蛛隔离、关键词统计、访客时长/页数等指标。

## 功能特性
- 站点注册 / 删除，生成专属 `tracking_id` 并可一键复制埋点脚本；登录保护后台。
- JS 埋点脚本，自动上报页面 URL、来源、UA、IP（会进行哈希）、会话页数与停留时长；根域名默认合并 www 统计。
- 像素上报接口 `track.php`，兼容无需 JS 的场景。
- 仪表盘按「总览 / 内容 / 趋势 / 移动端 / 蜘蛛」分屏展示，新增「来路分析」（搜索引擎 / 关键词 / 外部链接）栏目，支持多站点切换只看当前站数据，可在卡片选择时间范围（今日 / 昨日 / 近 7 天 / 近 30 天）。
- 蜘蛛抓取数据单独罗列，不计入正常统计；支持基于历史均值的今日 PV/UV/IP 预估，额外提供按日趋势与按小时分布表以还原 51LA v6 视图。
- 移动端专属统计页：按受访域名展示 PV/IP 与移动 PV/IP 汇总。
- 支持可配置的数据保留天数与每日自动清理；删除站点会清空其全部历史数据并同步更新分享页。

## 快速开始
1. 导入数据库表结构：
   ```sql
   SOURCE schema.sql;
   ```
2. 配置环境变量（或直接编辑 `config/config.php`）：
   - `DB_DSN` 示例：`mysql:host=127.0.0.1;dbname=analytics;charset=utf8mb4`
   - `DB_USER` / `DB_PASS`
   - `REDIS_HOST` / `REDIS_PORT`
   - `APP_BASE_URL`：对外访问的根地址，用于生成埋点脚本 URL。
   - `ADMIN_USER` / `ADMIN_PASS`：后台登录账号密码。
3. 确保 PHP 具备 PDO MySQL 与 Redis 扩展。
4. 部署 Nginx + PHP-FPM（可参考 `nginx.conf`），站点根目录指向 `public/`；已在示例中加入兼容 Tengine 2.2.3 的伪静态回退（`try_files` + `@rewrite`）。

## 埋点方式
在需要统计的站点 `<head>` 中插入（替换 `YOUR_TRACKING_ID`）：
```html
<script src="https://your-domain.com/js/tracker.js" data-site="YOUR_TRACKING_ID"></script>
```
脚本会加载 1x1 像素到 `/track.php` 完成上报；也可以直接使用图片：
```html
<img src="https://your-domain.com/track.php?sid=YOUR_TRACKING_ID" style="display:none" alt="" />
```

## 文件概览
- `public/index.php`：登录页，验证成功后进入域名列表。
- `public/sites.php`：域名列表/新增/删除与埋点代码查看。
- `public/overview.php`、`content.php`、`trend.php`、`mobile.php`、`bot.php`：侧边栏切换的分屏数据页。
- `public/search_engine.php`、`keyword.php`、`external.php`：来路分析栏目（搜索引擎 / 关键词 / 外部链接）。
- `public/track.php`：像素上报接口，写入 MySQL 并用 Redis 去重 UV。
- `public/js/tracker.js`：前端埋点脚本。
- `src/Tracker.php`：核心逻辑，包括站点管理、PV/UV 记录与统计查询。
- `schema.sql`：数据库结构。
- `nginx.conf`：示例 Nginx 配置（根目录 `/var/www/html/public`，PHP-FPM 监听 `php-fpm:9000`）。

## IP 位置与运营商识别
- 系统支持使用 **纯真 QQWry IPIP.ipdb** 做省份 / 国家和运营商解析。默认读取 `data/qqwry.ipdb`，可通过环境变量或 `config/config.php` 的 `ipdb.path` 指定。
- 推荐通过 Composer 引入官方 `ipip/db` Reader（或在服务器上提供同名类），系统会自动调用；若缺失则回退到基础网段提示，地图/地域统计将退化为“未知”。
- 新增字段：`country_name`、`region_name`、`city_name`、`isp_domain`、`country_code`、`continent_code`，并附带国家/省份/运营商索引，已有库可运行后自动迁移。

## 安全与隐私提示
- 访客 IP 会进行 SHA-256 哈希后再存储，示例代码仍需根据合规要求补充匿名化、隐私政策展示等内容。
- 上线前请为接口增加认证、速率限制、CSP 等安全措施。

## 大数据量优化建议
- `pageviews` 表默认包含复合索引（站点+时间、站点+蜘蛛、站点+移动端、站点+域名、站点+会话）以支持 20+ 站点与亿级日访问查询。
- 建议为高并发环境开启 MySQL 分区（如按日期 RANGE 分区或 HASH(site_id) 分区）并启用冷热数据分库分表。
- Redis 用作 UV 去重和站点缓存，可按 `REDIS_PREFIX` 区分实例或采用哨兵集群；必要时可在业务层增加按天汇总表减少实时聚合压力。

## 大型统计站点的分层架构（新增）
- **异步采集管道**：`config/ingest.mode` 设为 `queue` 时，上报写入 Redis 队列（`tracker:ingest:pageviews`），由 `cli/ingest_worker.php` 批量入库并驱动 rollup 汇总，削峰填谷、避免前端请求阻塞。
- **实时与汇总合并**：已有小时级 rollup 会在入库时同步更新，统计页按窗口优先走 rollup，缺口再查原始表，保证在 rollup 完整覆盖时仅需扫聚合表即可。
- **分区与站点分片**：核心表支持 HASH(site_id) 分区，单站点数据按分区落盘，可在 MySQL 8+ 上保持表规模扩展同时让单站查询命中更小分区；也可按月 RANGE 分区再叠加 HASH 做二级分片。
- **运维建议**：使用 `php cli/ingest_worker.php --loop --sleep=1 --max=1000` 常驻监听队列，或通过 systemd/cron 定时执行；`config/ingest.max_queue_length` 用于防止异常堆积。
