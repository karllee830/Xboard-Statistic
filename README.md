# Xboard Statistics

Xboard Statistics 是面向 Xboard 与 Xboard-Node Sing-box 内核的分钟级多维流量统计插件。

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

插件在 Xboard 左侧边栏只提供一个“流量分析”入口。入口会离开通用 CRUD 页面，直接打开完整的透视分析页：

```text
https://你的面板域名/{secure_path}/xboard-statistic
```

`secure_path` 与 Xboard 管理后台的安全访问入口一致。页面进入前会检查管理端本地 Bearer token，并通过受保护接口确认管理员身份；未登录、登录过期或没有管理员权限时会直接跳转到后台登录页，登录成功后自动返回流量分析页。所有统计数据请求仍经过 Xboard `admin` 和 `log` 中间件。

页面默认提供两条连续下钻路径：

- 节点视角：节点排行 → 节点内用户排行 → 指定用户在该节点的域名排行。
- 用户视角：用户排行 → 用户使用的节点排行 → 指定用户在该节点的域名排行。

当前分析路径会保留在面包屑和 URL 中，可随时返回任意上游层级。选中域名后还能继续查看目的 IP、出站、应用协议、目的端口或目的国家分布。IP、网络、协议、入站、出站、端口、国家和 ASN 等条件收纳在“更多筛选”中，并叠加到当前路径。

主排行使用“流量路径 / 来源 / 去向”三级模式：切换模式不会清空当前节点、用户、域名或高级筛选，来源与去向还会分别记住上次选择的 IP、国家、ASN 等维度。“来源 IP”概览卡可一键进入来源视图，流量路径完成后自动切换到去向视图。来源 IP 行会直接合并显示国家、ASN 和 AS 组织；来源国家使用本地化国家名与旗帜，来源 ASN 聚合同时显示组织名称。若启用“页面隐藏来源 IP”，地址仍按 IPv4 `/24`、IPv6 `/48` 掩码展示，国家和 ASN 信息保留。

交叉查询 API：

```text
GET /api/v2/{secure_path}/plugin/xboard-statistic/overview
GET /api/v2/{secure_path}/plugin/xboard-statistic/health
GET /api/v2/{secure_path}/plugin/xboard-statistic/traffic
GET /api/v2/{secure_path}/plugin/xboard-statistic/ranking/{group}
```

`health` 返回各节点最新分钟桶、接收延迟、节点端待报队列和重试次数。`traffic` 支持 `from`、`to`、`group_by`、`node_id`、`user_id`、`domain`、`source_ip`、`destination_ip`、`network`、`application_protocol`、`inbound_type`、`outbound`、`destination_port`、来源/目的国家和 ASN 等参数。查询范围上限为 90 天，返回行数有硬限制；趋势仍按小时聚合，避免长时间范围产生过密折线。

## 时间与时区约定

- 节点上报的 `bucket_start`、`bucket_end` 必须是 UTC 整分钟或整小时，并带 `Z`。
- 批次时间、接收时间和明细时间以 Unix 秒字段作为查询、排序和延迟计算的权威值；兼容时间字段统一写成 UTC `YYYY-MM-DD HH:MM:SS`。
- 管理 API 的输入必须是带 `Z` 或明确偏移量的 ISO 8601，输出统一为带 `Z` 的 UTC ISO 8601。
- 页面中的时间筛选使用浏览器本地时区，提交时转换为 UTC；健康时间、数据更新时间和趋势横轴再由浏览器转换为用户时区展示。
- 日汇总的 `day` 是 UTC 自然日，定时任务也固定按 UTC 调度，不受 Xboard、PHP、数据库或宿主机时区影响。

## 节点配置

使用本项目配套修改后的 Xboard-Node fork，并仅在 Sing-box 节点上启用：

```yaml
statistics:
  enabled: true
  spool_path: /etc/Xboard-Node/statistics
  max_hourly_dimensions: 200000
  max_domains_per_user_hour: 5000
  max_destination_ips_per_user_hour: 10000
  max_pending_batches: 10080
  request_timeout: 30
  disable_sniff: false
```

详细统计按 UTC 分钟生成 schema v2 不可变 gzip 批次，通常在下一次分钟检查时上传；插件继续兼容旧节点的 schema v1 小时批次。失败批次原子持久化并指数退避重试；4xx 永久拒绝批次移到 `rejected` 目录。该链路独立于原计费上报。

## 日汇总、清理和 GeoIP

插件会由 Xboard Scheduler 自动运行以下命令，也可手动执行：

```bash
php artisan xboard-statistic:rollup --date=2026-07-28
php artisan xboard-statistic:cleanup --dry-run
php artisan xboard-statistic:update-geoip --force
php artisan xboard-statistic:enrich-ips
```

日汇总包含节点×用户、节点×域名、用户×节点、用户×域名、来源 IP×用户、目的 IP×域名。分钟事实、日汇总和批次元数据分别按插件设置保留。

国家与 ASN 只通过面板服务器上的离线 MMDB 查询，不会把访问 IP 发送给在线查询接口。插件安装包内置 MMDB 读取器，默认自动下载并按月更新 DB-IP Country Lite 和 ASN Lite，文件持久化到 Xboard 的 `.docker/.data/xboard-statistic/geoip`；Docker 容器重建或插件升级不会清空该目录。更新采用临时文件下载、MMDB 类型校验和原子替换，失败时继续使用上一份可用数据库。

设置中的 Country/ASN MMDB 路径现在只是可选覆盖项：留空即可自动维护；填写后优先读取指定的 MaxMind GeoLite2/GeoIP2 或其他兼容 MMDB。目的 IP 排行会直接显示国家、ASN 和 AS 组织。DB-IP Lite 数据按 [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/) 使用，页面保留 `IP Geolocation by DB-IP` 署名链接。

## 隐私提示

访问域名和来源 IP 属于敏感数据。插件查询仅允许管理员，查询进入 Xboard 请求日志；建议设置最短可用保留期，并按需启用“页面隐藏来源 IP”。该开关将 IPv4 显示为 `/24`、IPv6 显示为 `/48`，底层数据仍保留用于统计。

版本化协议定义见 `resources/schema/report-v1.schema.json` 与 `resources/schema/report-v2.schema.json`。Schema 明确禁止额外字段，避免节点静默增加 User-Agent 等未授权维度。
