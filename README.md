# 合集 · Laravel 13 三站系统

这是一套共用 Laravel 的中文下单、采购库存与食谱应用。三个域名使用同一 PostgreSQL 数据库，共享账号与业务服务。Nginx 按 Host 选择公共目录，每个 PHP 入口还会核对自己的目录名。会话使用 Redis，浏览器 Cookie 限于当前域名。

| 网址 | 公共目录 | 前台 | 后台 |
| --- | --- | --- | --- |
| shop.laravel.demo | public/shop | 商品、手机购物车、下单、自己的订单 | 商品、订单处理与取消 |
| stock.laravel.demo | public/stock | 经授权员工提交采购、查询自己的采购单 | 物品、采购收货、调整库存与流水 |
| recipe.laravel.demo | public/recipe | 根路径进入受保护的后台 | 食谱、食材、饮食日历、下周补货 |

本系统已通过 Docker Compose 完成部署，默认数据目录 `/srv/heji`；自定义部署沿用相同的 `HEJI_CONFIG_DIR`、`HEJI_ENV_FILE`、`HEJI_VAR`。本文档面向已安装环境，只覆盖日常运维、版本更新与业务规则，不包含初始化安装步骤。

## 运行架构与版本策略

运行栈为 PHP 8.4+、Laravel 13、PHPUnit 12、PostgreSQL 与 Redis。数据库要求 PostgreSQL 18 或更新（当前 latest 为 19），版本门禁只校验下限，不锁死主版本。Compose 默认镜像标签为 `latest`；发布时拉取 `postgres:latest`、`redis:latest` 并按同一摘要用于隔离测试与生产。应用、Web、测试、浏览器、日志镜像均在本地构建，验收通过后才更新本机 `latest` 标签。

应用镜像内的 `pg_dump / pg_restore / psql / libpq` 从 PostgreSQL 官方源码编译：构建期自动解析 ftp.postgresql.org 上最新稳定版号，下载 tarball 并以官方同源 `.sha256` 校验，只编译客户端与 libpq（启用 openssl、zlib），保证客户端主版本始终不低于服务端。源码目录索引以 `ADD` 引入，新版本发布时仅重建客户端层；构建结束执行 `pg_dump --version` 等自检。

生产运行账号为最小权限的 `heji_app`，迁移账号为 `heji_owner`。数据库与 Redis 不发布主机端口；只有运维容器挂载 Docker 套接字。容器启用 `no-new-privileges`，日志容器无网络、只读、丢弃全部 capability。`.env` 权限 `0600`，密钥与密码为安装时随机生成；请保留原始 `.env` 与数据卷，尤其是 `APP_KEY` 和数据库凭据。

## 日常运维

日常命令使用 `sh bin/heji`，它通过一次性运维容器对线上生产栈执行 `docker compose` 子命令，始终锁定当前已验收版本的归档编排与生产 `.env`：
```sh
sh bin/heji compose ps
sh bin/heji compose up -d --no-build --wait
sh bin/heji compose exec -T app php artisan heji:developer aliencbh@gmail.com
```

依次为：查看生产栈各容器状态与健康检查；重启已验收版本（不构建镜像、不跑迁移、不动数据卷，等待健康检查通过才返回）；给已在店铺注册的账号授予开发员资格（把 `address@example.com` 换成真实邮箱，解锁开发者页面与角色权限管理）。若 app 容器未运行，授权改用 `sh bin/heji compose run --rm --no-deps -T app php artisan heji:developer 邮箱`。

证书续期一条命令完成：导入新证书（核对有效期、三个域名和密钥匹配，私钥权限 `0600`），随后自动 `nginx -t` 校验并热加载；导入失败不会触发 reload，web 未运行时证书落盘待启动生效：
docker compose --env-file /dev/null -f compose.setup.yaml run --rm -T --build
-v /absolute/path/to/certificates:/input:ro ops tls-renew /input/fullchain.pem /input/privkey.pem

容器统一挂载 `storage/logs`，宿主机路径为 `$HEJI_VAR/logs`，分类为 laravel、php、nginx、postgres、redis、scheduler、runtime、tests。`logs` 服务经本地 Unix 套接字收集容器输出写入 `runtime/`，服务自身错误文件按类型保存。当前版本号在 `$HEJI_VAR/current`，历次发布报告在 `$HEJI_VAR/releases/<版本>/`。请为持久卷与备份安排磁盘容量、保留期限和异机副本。

## 版本更新与自动验收

合入 main 后，带 `self-hosted`、`linux`、`heji-production` 标签的 GitHub Actions runner 自动执行验收与发布；手动更新执行 `sh bin/heji release [唯一版本号]`，走同一条流程。运维容器通过共享部署目录上的文件锁防止并发发布；构建、测试、恢复或迁移报错即返回非零。GitHub 的 production environment 仅允许可信维护人员修改 main 和发布工作流。

| 阶段 | 行为与产物 |
| --- | --- |
| 生成候选版本 | 整个版本使用同一份 Composer 锁。源码已有锁时优先使用；依赖声明未变时复用上次成功发布的锁，并校验锁内确为 Laravel 13。 |
| 隔离与清日志 | 独立 Compose 项目、数据库卷、Redis 与临时凭据，随机回环端口避免触及生产监听。首次测试前清空测试日志，已发布站点的测试日志索引同步归零。 |
| PHP 与功能验收 | `composer audit` 检查依赖安全公告；迁移、Blade 编译、路由清单、PHP 语法检查；PHPUnit 17 个功能用例输出 JUnit。 |
| 方法覆盖门槛 | Xdebug 记录 `app/` 自有类每个具名方法的执行；`bin/report.php` 要求每个方法都被至少一个通过的用例执行过，否则阻止发布。分支覆盖率需单独评估。 |
| 运维与编排回归 | `tests/operations.py` 覆盖并发初始化、证书导入与权限、运维命令与升级兼容；`tests/compose_images.py` 校验默认 latest 标签、仅运维容器挂 Docker 套接字、fluentd 日志指向、测试与生产同一镜像摘要。 |
| 三站实测 | 真实 Nginx、PHP-FPM、PostgreSQL、Redis。逐域核对 HTTPS、专属公共目录标记、登录跳转和 CSRF；Node 控件测试与 Playwright 浏览器交互。 |
| 备份恢复验证 | 在 db 容器内 `pg_dump` 归档，恢复到独立空库 `heji_restore` 并查询角色数据，验证归档可实际恢复。 |
| 切换 | 验收通过后先对现有生产版本做数据库备份，再用迁移账号执行迁移与授权。切换到已测试镜像后逐域检查 `/health`。健康检查失败时回滚上一版应用镜像；`latest` 提升中途失败也会回退标签。 |

报告包含依赖锁及其哈希、路由清单、lint、JUnit、方法覆盖记录、按来源归类的测试日志、数据镜像摘要与 `exit-code`。可将生成的锁提交到仓库以便在其他机器复现。生产数据库迁移须保持与上一版应用兼容（镜像回退不逆转数据迁移）；需要移除字段的改动，先发布兼容读写，再单独迁移数据及移除旧结构。

## 操作与规则

**店铺和库存。** 示例币种为马币 RM。商品档案共享，下单按数据库当前售价创建订单行快照并扣库存，库存不足整单回滚；重复提交同一 checkout nonce 不重复记账。待处理订单可完成或取消，取消回补库存。采购申请被收货后增加库存，重复收货不重复记账。手工调整必须记录原因；物品被单据引用后保留历史关联，删除受外键保护并给出提示。

**食谱。** 食材档案保存统一名称、单位、注意事项、冷藏方式、真空包装、腌制、每周购买、库存及囤货目标。食谱材料按食材和数量关联，修改注意事项会反映到所有引用它的食谱。荤菜和素菜必须实际使用所选主食材。新食谱默认为好吃、尚未煮过。有编辑权限的成员可在食谱详情页直接填写并保存「需要调整」，其他成员按查看权限阅读记录。

**排期。** 使用马来西亚时间。日历默认显示当前月份，可切换周/月视图并前后翻页；月视图按设置的周首日对齐，窄屏可点选日期跳到当天菜单。「连续排期」填写明天或以后的起始日期与正整数天数，可一次安排 121、366 或更长，整个范围在同一事务内保存。调度器每日 00:05 维护从明天起的 14 天；重新排版及修改星期规则也从明天起作用，今天已有的菜单仍可查看、手动交换或点「今天没煮」顺延。

- 自定义周的前三天按鱼、虾、sotong 排荤菜，其余天用其他荤菜；每天搭配一素一汤，周末另加一道「周末其他」。相邻两天检查全部已登记食材（含作为材料登记的调料）。
- 「今天没煮」将今天整份菜单移到明天、明天移到后天，直至原排期最后一天，跨周跨月保留原菜式；重复点击同一天只生效一次。自动排期保留顺延菜式；解冻提示和下周补货按新的用餐日计算。
- 可拖动或触屏点选交换两份菜式，两端按类别、固定荤菜和相邻日规则校验。「重新排版」限于当前显示的月/周，从明天与该周期首日中较晚的一天排到周期末。
- 菜库不够时显示具体原因；无解或搜索超限时整次排期回滚。标记不好吃会移除未来安排中的该食谱，可重新排版补齐。
- 每个用餐日显示本日需解冻食材。「下周补货」按所设周起始日计算下一个完整星期，购买量为 `max(0, 下周用量 + 用完后要留存的量 − 当前剩余)`；仅可囤货食材计入留存目标，清单标注需腌制项。排期不足七天时显示完成天数。

**后台表格。** 点击标题排序，拖动标题调整字段次序；勾选行可只看/隐藏选中行，表头复选框全选本页，选择可跨页保留；字段可隐藏，清除按钮重置该字段搜索。默认每页 5 条，可切换每页数量并输入页码。CSV 由服务器对当前筛选结果导出，遵循可见字段顺序、行筛选和勾选范围，可跨分页，并对可能被电子表格解释为公式的值做转义。

**角色和安全。** 初始十个角色按需求创建；开发员资格在服务器授予，其余角色权限按网址、控制器和操作分配，支持新增及删除空角色。新注册用户可在店铺下单，后台业务权限初始为空。应用在服务端用 Gate 检查读取、编辑、删除、导出和开发者页面。登录采取限流和会话轮换；Blade 输出转义，数据库查询使用绑定值，排序字段和资源名使用白名单。商品图片限制 JPG/PNG、2 MB、3000×3000 像素，解码并重编码为 PNG 后保存在私有路径，经 `/media/{hash}` 以 `nosniff` 提供。

## 开发者页面与数据恢复

| 页面 | 用途 |
| --- | --- |
| 数据库日志 | PostgreSQL BEFORE 触发器（SECURITY DEFINER）在同一事务写入 `audit.<业务表同名>`，记录时间、用户、动作、记录号及新旧行，屏蔽密码散列和记住登录令牌；系统调度无登录用户时 actor 为空。支持按表查看、筛选和勾选删除。 |
| 错误 / 测试日志 | 调度器分钟级将新增日志导入可搜索的数据库索引；后台可新增、更正、删除索引条目，原始文件按服务保留。测试来源为 common 与三个完整域名。 |
| 数据库备份 | 手动或按间隔（5–525600 分钟）执行 `pg_dump` 自定义归档，命名 `YYmmddhhmmss.dump`，支持日期区间、改备注、下载与删除。备份内容为整个应用数据库，含 audit 模式。目录权限 0700、文件 0600，下载响应 `no-store`。 |
| 服务器状态 | 显示运行版本、负载、cgroup 资源统计、磁盘容量，以及服务器到当前浏览器的 HTTPS 下载测速（1 MiB）。 |

备份下载是单一应用数据库的逻辑归档（`pg_dump --format=custom`，非纯 SQL，须用 `pg_restore` 读取）。恢复在新建空库进行，命令在 db 容器内执行，客户端与服务端同版本。`backup-list` 只读打印归档目录清单（TOC），不恢复数据；`restore` 以单事务、遇错即停方式写入独立空库，并拒绝在线库 `heji` 与系统库 `postgres/template0/template1`。

先建空库，再检查归档，最后恢复（`heji_restore` 为独立空库名，可自定义但须匹配小写标识符）：
```bash
sh bin/heji compose exec -T db createdb -U heji_owner heji_restore
docker compose --env-file /dev/null -f compose.setup.yaml run --rm -T --build
-v /absolute/path/to/backups:/input:ro ops backup-list /input/example.dump
docker compose --env-file /dev/null -f compose.setup.yaml run --rm -T --build
-v /absolute/path/to/backups:/input:ro ops restore /input/example.dump heji_restore
```

PostgreSQL 角色是集群级的：恢复到同一 db 容器的新库时 `heji_owner/heji_app` 已存在可直接用；恢复到另一台服务器须先建好所用角色，否则遇错即停会因属主/ACL 缺失而中止。恢复后核对网站账号、权限、订单金额与库存、日历及图片，再切换流量；仅演练时事后 `dropdb -U heji_owner heji_restore` 清理。

图片、TLS 证书、应用密钥、原始日志和归档文件保存在数据库外，应与数据库归档分别备份。

## 代码入口

| 位置 | 内容 |
| --- | --- |
| routes/web.php、routes/console.php、config/heji.php | 域名、路由、调度、权限和业务选项 |
| app/Http、app/Services、app/Support | 控制器、中间件、库存、排期、备份及表格导出 |
| database/schema.sql、database/migrations | 表、外键、检查约束及审计触发器 |
| resources/views、resources/assets、public | Blade 页面、无外部 CDN 的前端资源与站点入口 |
| tests、bin/test、bin/report.php | 功能/浏览器/运维测试、方法覆盖门槛与日志 |
| docker、compose*.yaml、bin/release、.github/workflows | 镜像、服务编排与自动发布 |

框架与数据库文档：[Laravel 13 发布说明](https://laravel.com/docs/13.x/releases) · [Laravel 请求伪造防护](https://laravel.com/docs/13.x/csrf) · [PostgreSQL 18 pg_dump](https://www.postgresql.org/docs/18/app-pgdump.html) · [Docker Compose 镜像构建](https://docs.docker.com/reference/compose-file/build/)
