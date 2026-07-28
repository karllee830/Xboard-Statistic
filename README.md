# Xboard Statistics

Xboard Statistics 是面向 Xboard 与 Xboard-Node Sing-box 内核的小时级多维流量统计插件。

当前版本支持节点、用户、域名、来源 IP、目的 IP、网络、应用协议、入站、出站、目标端口、国家和 ASN 的排行、趋势、组合筛选及交叉下钻。User-Agent、URL 路径、查询参数、Cookie、正文和数据包内容均不会采集。

## 安装目录

将本仓库放置为 Xboard 的：

```text
plugins/XboardStatistic
```

然后在 Xboard 管理端安装并启用插件。插件启用后提供节点上报接口：

```text
POST /api/v2/server/statistics/report
```

接口沿用 Xboard `server.v2` 节点认证。请求正文可使用 gzip，并必须带有解压后 JSON 正文的 SHA-256：

```text
X-Statistics-Checksum: <64 lowercase hex characters>
X-Xboard-Token: <server or machine token>
X-Xboard-Node-ID: <node id>
X-Xboard-Machine-ID: <machine id, machine mode only>
Content-Encoding: gzip
Content-Type: application/json
```

认证字段只通过请求头发送，不写入本地待报批次。插件的解压中间件会在内存中把这些字段交给 Xboard `server.v2` 验证。

本插件不改变 Xboard 原有计费上报逻辑。第一阶段仅支持修改后的 Xboard-Node Sing-box 内核。

## 管理页面

插件通过 Xboard 的 `admin_menus` / `admin_crud` 提供节点、用户、域名、来源/目的 IP、出口、国家和 ASN 原生排行页。完整的联动筛选页面位于：

```text
https://你的面板域名/xboard-statistic
```

先登录 Xboard 管理端再打开该页面。页面从管理端本地登录状态读取 Bearer token；所有统计数据请求仍经过 Xboard `admin` 和 `log` 中间件。未登录用户只能取得空的 HTML 外壳，无法读取数据。

交叉查询 API：

```text
GET /api/v2/{secure_path}/plugin/xboard-statistic/overview
GET /api/v2/{secure_path}/plugin/xboard-statistic/health
GET /api/v2/{secure_path}/plugin/xboard-statistic/traffic
GET /api/v2/{secure_path}/plugin/xboard-statistic/ranking/{group}
```

`health` 返回各节点最新小时桶、接收延迟、节点端待报队列和重试次数。`traffic` 支持 `from`、`to`、`group_by`、`node_id`、`user_id`、`domain`、`source_ip`、`destination_ip`、`network`、`application_protocol`、`inbound_type`、`outbound`、`destination_port`、来源/目的国家和 ASN 等参数。查询范围上限为 90 天，返回行数有硬限制。

## 节点配置

使用本项目配套修改后的 Xboard-Node fork，并仅在 Sing-box 节点上启用：

```yaml
statistics:
  enabled: true
  spool_path: /etc/Xboard-Node/statistics
  max_hourly_dimensions: 200000
  max_domains_per_user_hour: 5000
  max_destination_ips_per_user_hour: 10000
  max_pending_batches: 168
  request_timeout: 30
  disable_sniff: false
```

详细统计按 UTC 小时生成不可变 gzip 批次。失败批次原子持久化并指数退避重试；4xx 永久拒绝批次移到 `rejected` 目录。该链路独立于原计费上报。

## 日汇总、清理和 GeoIP

插件会由 Xboard Scheduler 自动运行以下命令，也可手动执行：

```bash
php artisan xboard-statistic:rollup --date=2026-07-28
php artisan xboard-statistic:cleanup --dry-run
php artisan xboard-statistic:enrich-ips
```

日汇总包含节点×用户、节点×域名、用户×节点、用户×域名、来源 IP×用户、目的 IP×域名。小时事实、日汇总和批次元数据分别按插件设置保留。

国家与 ASN 只使用面板服务器本地 MaxMind MMDB，不进行在线 IP 查询。需要在 Xboard 根目录安装 `geoip2/geoip2`，然后在插件设置中填写 Country 和 ASN MMDB 路径；未配置时相关维度显示为 `unknown`。

## 隐私提示

访问域名和来源 IP 属于敏感数据。插件查询仅允许管理员，查询进入 Xboard 请求日志；建议设置最短可用保留期，并按需启用“页面隐藏来源 IP”。该开关将 IPv4 显示为 `/24`、IPv6 显示为 `/48`，底层数据仍保留用于统计。

版本化协议定义见 `resources/schema/report-v1.schema.json`。Schema 明确禁止额外字段，避免节点静默增加 User-Agent 等未授权维度。
