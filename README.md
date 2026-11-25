# 禾集 · Laravel 13 三站系统

这是一套共用 Laravel 的中文下单、采购库存与食谱应用。三个域名使用同一 PostgreSQL 18 数据库，共享账号与业务服务。Nginx 按 Host 选择公共目录。每个 PHP 入口还会核对自己的目录名。会话使用 Redis，浏览器 Cookie 限于当前域名。

| 网址 | 公共目录 | 前台 | 后台 |
| --- | --- | --- | --- |
| shop.laravel.demo | public/shop | 商品、手机购物车、下单、自己的订单 | 商品、订单处理与取消 |
| stock.laravel.demo | public/stock | 经授权员工提交采购、查询自己的采购单 | 物品、采购收货、调整库存与流水 |
| recipe.laravel.demo | public/recipe | 根路径进入受保护的后台 | 食谱、食材、饮食日历、下周补货 |

**交付验证状态。** 源码、容器构建文件、测试和自动发布工作流均在本包内。新增的 6 项运维回归测试已通过，覆盖并发初始化、证书与权限、管理命令和升级兼容性。发布时另检查容器挂载及日志能否实际写入。制作环境执行了 8 项 Node 前端逻辑测试、密钥及三域名证书初始化检查、脚本与配置结构检查。使用 SQLite 检查了 121、366 和 731 天的逐日顺延 SQL 与重复点击。模拟命令验证了七种发布结果，包括成功、测试失败、日志未写入、迁移失败、健康检查失败，以及有无旧版本时更新 latest 标签中途失败。测试失败退出码 31 会阻止生产部署。该环境缺少 PHP、Composer、Docker，且限制本机 Chromium 启动。PHPUnit、真实 HTTPS、Chromium 页面、PostgreSQL 恢复与生产切换的实际结果由目标 Docker 主机生成。当前包不附带一次成功发布的报告。

**已有项目升级。** 从存放 ZIP 和原有 `heji/` 的目录执行下面的第一条命令，把文件名换为 `heji-docker-ops-update.zip`。保留原部署目录、密钥和数据卷，再运行发布命令。使用过自定义存储路径的部署应继续设置相同的 `HEJI_CONFIG_DIR`、`HEJI_ENV_FILE` 和 `HEJI_VAR`。配置文件和数据必须位于挂载给运维容器的配置目录内。

## 使用 Docker Compose 安装与发布

在使用本机 Docker Engine 的 Linux 服务器上运行，账号须有权调用 Docker 和 Compose v2。把三个域名的 DNS 指向服务器。默认项目数据目录是 `/srv/heji`，Docker 首次挂载时创建。依赖安装、目录创建、文件读写、数据库迁移和权限设置都在容器中完成。仅需在服务器使用 Docker 和常规 shell 命令。构建时需连接镜像仓库及依赖源。

**1. 用容器解压源码并初始化。** 在含有下载包的目录执行。解压容器按当前账号身份写入源码，运维容器生成持久化密钥，重复运行会保留已有密钥。

```sh
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/package" -w /package alpine:latest unzip -o heji-laravel13.zip
cd heji
docker compose --env-file /dev/null -f compose.setup.yaml run --rm -T --build ops init
```

**2. 将 HTTPS 证书交给容器导入。** 以下示例假设证书链与私钥已放在服务器的 `/absolute/path/to/certificates`，请换成真实的绝对路径。命令会核对有效期、三个域名和密钥匹配情况，私钥权限为 `0600`。仅作内网测试时，可改用 `sh bin/heji init --local-cert` 生成证书，并在访问设备上信任它。

```sh
docker compose --env-file /dev/null -f compose.setup.yaml run --rm -T --build \
  -v /absolute/path/to/certificates:/input:ro ops tls-import /input/fullchain.pem /input/privkey.pem
```

**3. 发布并查看服务。** 运维容器构建镜像，整理数据卷权限，启动隔离测试，归档报告，检查三个站点的 HTTPS 后更新版本。日常命令可使用 `sh bin/heji`，它只负责启动同一个 Compose 运维容器。

```sh
docker compose --env-file /dev/null -f compose.setup.yaml run --rm -T --build ops release
sh bin/heji compose ps
```

Compose 默认镜像标签为 `latest`。发布会拉取 PostgreSQL 和 Redis 的镜像，以同一摘要测试和上线。数据库必须为 PostgreSQL 18。验收通过后才更新本机的 `latest` 标签。重启已验收版本可执行 `sh bin/heji compose up -d --no-build --wait`。已部署站点更新证书后，可执行 `sh bin/heji compose exec -T web nginx -s reload`。

**4. 初始开发员和示例数据。** 在店铺的 `/register` 注册账号后，在容器中授权。随后可在「开发者 → 页面 → 角色与权限」分配角色。按需执行后两条命令载入演示数据并排餐。

```sh
sh bin/heji compose exec -T app php artisan heji:developer your-address@example.com
sh bin/heji compose exec -T app php artisan db:seed --class=DemoSeeder --force
sh bin/heji compose exec -T app php artisan heji:menu
```

运维容器仅在执行管理命令时连接本机 Docker 引擎，拥有部署主机的 Docker 管理权限。源码只读挂载，临时构建目录、测试凭据和报告均在部署数据目录内处理。后台服务与日志采集容器均不挂载 Docker 套接字。Compose 的 `logs` 服务经本地 Unix 套接字收集容器输出，写入 Laravel 日志目录的 `runtime/`。服务自身的错误文件继续按 Laravel、PHP、Nginx、PostgreSQL、Redis 等类型保存。如果旧版已安装 systemd 采集服务，发布成功后可用以下一次性容器命令停用旧单元。命令核对旧单元文件与项目原件一致，并确认新版日志服务正在运行，随后取消旧单元自启并停止旧进程。全新安装可直接跳过。

```sh
docker compose --env-file /dev/null -f compose.setup.yaml run --rm -T --build \
  -v /etc/systemd/system:/legacy-units:ro \
  -v /run/dbus/system_bus_socket:/run/dbus/system_bus_socket ops retire-legacy
```

## 自动测试与自动上版本

将本目录作为私有仓库根目录。在专用 Linux 部署主机配置带 `self-hosted`、`linux`、`heji-production` 标签的 GitHub Actions runner，使用上述持久数据目录及可信 TLS 证书。在 GitHub 配置 production environment，仅允许可信维护人员修改 main 和发布工作流。合入 main 后，Docker 容器检出指定提交，运维容器运行验收并发布，临时检出目录也由容器清理。手动发布也走同一条验收流程。

| 阶段 | 行为与产物 |
| --- | --- |
| 生成候选版本 | 整个版本使用同一份 Composer 锁。首次构建通过 `^13.0` 解析，保存实际的 `composer.lock`。源码已有锁时优先使用。依赖声明未变时复用上次成功发布的锁。 |
| 隔离与清日志 | 使用独立 Compose 项目、数据库卷、Redis 和临时凭据。首次测试前清空当前测试日志。已发布站点的测试日志索引同步归零，历次发布报告按版本保留。 |
| PHP 与功能验收 | 检查依赖安全公告、迁移、Blade 编译、路由、PHP 语法及 PHPUnit 断言。包括版本校验、站点权限、账户、库存事务、重复提交、食谱排期、补货、备份和审计。 |
| 每个方法的记录 | 每个测试记录开始、请求和状态码。JUnit 给出断言结果，Xdebug 关联 `app/` 自有类的具名方法与执行过它的用例。任何具名方法缺少通过用例的执行记录都会阻止发布。该门槛衡量方法执行与用例结果，分支覆盖率需单独评估。 |
| 三站实测 | 使用真实 Nginx、PHP-FPM、PostgreSQL 18 和 Redis。逐域核对 HTTPS、专属公共目录标记、登录跳转和 CSRF，运行浏览器交互。将 PostgreSQL 归档恢复到另一测试库并查询角色数据。 |
| 切换 | 各项验收通过后，对已有生产版本先做数据库备份，再使用迁移账号执行迁移。切换到已测试镜像后逐域检查健康状态。健康检查失败时恢复上一版应用镜像，初次发布的健康检查失败则停止网站容器与调度器。 |

运行 `sh bin/heji release <唯一版本号>` 可自定义版本号。构建、测试、恢复或迁移报错即返回非零。运维容器通过共享部署目录上的文件锁防止两个发布同时运行。生产数据库迁移需要保持与上一版应用兼容，镜像回退不会逆转数据迁移。对未来需要移除字段的改动，先发布兼容读写，再单独迁移数据及移除旧结构。

报告位于 `$HEJI_VAR/releases/<版本>/`，包含依赖锁、路由清单、JUnit、函数执行记录、按来源归类的测试日志及 `exit-code`。成功版本号写入 `$HEJI_VAR/current`。可将生成的锁提交到自己的仓库，以便在另一台机器上复现。同一套已部署数据需要保留原始 .env 和卷，尤其是 APP_KEY 和数据库凭据。

## 操作与规则

**店铺和库存。** 示例币种为马币 RM。商品档案共享，下单按数据库当前售价创建订单行快照并扣库存，库存不足整单回滚。待处理订单可完成或取消，取消回补库存。采购申请被收货后增加库存，重复收货不重复记账。手工调整必须记录原因，物品被单据引用后保留历史关联。

**食谱。** 食材档案保存统一名称、单位、注意事项、冷藏方式、真空包装、腌制、每周购买、库存及囤货目标。食谱材料按食材和数量关联，修改注意事项会反映到所有引用它的食谱。荤菜和素菜必须实际使用所选主食材。列表末尾提供详情、编辑、好吃与已煮按钮。新食谱默认为好吃、尚未煮过。有编辑权限的成员可在食谱详情页直接填写并保存「需要调整」，其他成员可按查看权限阅读记录。

**排期。** 使用马来西亚时间。日历默认显示当前月份，可切换周视图、月视图并前后翻页。月视图按设置的周首日对齐，窄屏可点选日期跳到当天菜单。在「饮食日历 → 连续排期」填写明天或以后的起始日期与正整数天数，可一次安排 121 天、366 天或更长。起始日也可以选在 90 天以后。整个范围在同一事务内保存。排程每天 00:05 维护从明天起的 14 天。重新排版及修改星期规则也从明天起作用，今天已有的菜单仍可查看、手动交换或点「今天没煮」顺延。每周起始日和周末可设置。自定义周的前三天按鱼、虾、sotong 排荤菜，其余天使用其他荤菜。每天搭配一素一汤，周末另加一道「周末其他」。相邻两天检查全部已登记食材。将每天都使用的调料登记为材料时，也会参与相邻日检查。

- 「今天没煮」将今天的整份菜单移到明天，明天的移到后天，直至原排期最后一天。荤菜、素菜、汤和周末加菜一并移动，跨周或跨月时保留原菜式。重复点击同一天只生效一次；第二天仍然没煮，可以再顺延一天。自动排期会保留顺延的菜式。解冻提示和下周补货按新的用餐日计算。手动重新排版或更改星期设置时，所选范围按当前星期规则重新安排。
- 可拖动两份菜式交换，触屏设备可依次点选。两端都按类别、固定荤菜和相邻日规则校验。「重新排版」限于当前显示的月份或星期，从明天与该周期首日中较晚的一天排到周期末。「连续排期」按填写的范围重新安排。
- 菜库不够时显示具体原因或冲突信息。无解或搜索超限时整次排期回滚。标记不好吃会移除未来安排中的该食谱，可重新排版立即补齐。
- 每个用餐日显示本日需要解冻的食材。「下周补货」按所设周起始日计算下一个完整星期。购买量为 `max(0, 下周用量 + 用完后要留存的量 − 当前剩余)`。仅可囤货的食材计入留存目标，购物清单标注需要腌制的品项。排期不足七天时显示完成天数。

**后台表格。** 点击标题排序，拖动标题调整字段次序。勾选行可只看选中行或隐藏选中行，表头复选框全选本页，选择可跨页保留。字段可隐藏。清除按钮重置该字段搜索。默认每页 5 条，可切换每页数量和输入页码。CSV 由服务器对当前筛选结果导出，遵循可见字段顺序、行筛选和勾选范围，可跨过当前分页。下载对可能被电子表格解释为公式的值做了处理。

**角色和安全。** 初始十个角色按需求创建。开发员资格在服务器授予。其余角色权限按网址、控制器和操作分配，支持新增及删除空角色。新注册用户可在店铺下单，后台业务权限初始为空。应用在服务端用 Gate 检查读取、编辑、删除、导出和开发者页面。登录采取限流和会话轮换，Blade 输出转义，数据库查询使用绑定值，排序字段和资源名使用白名单。商品图片限制为 JPG/PNG、2 MB、3000×3000 像素，解码并重编码后保存在私有路径。

## 开发者页面与数据恢复

| 页面 | 用途 |
| --- | --- |
| 数据库日志 | PostgreSQL BEFORE 触发器在同一事务写入 `audit.<业务表同名>`。记录时间、用户、动作、记录号及新旧行，屏蔽密码散列和记住登录令牌。新建记录的旧值为空，系统调度没有登录用户时 actor 为空。支持按表查看、筛选和勾选删除。 |
| 错误 / 测试日志 | 每分钟将新增的日志内容导入可搜索的数据库索引。后台可新增、更正、删除索引条目。原始文件按服务保留。测试来源为 common 与三个完整域名。 |
| 数据库备份 | 手动或按间隔执行 PostgreSQL 18 的 pg_dump，自定义归档名为 YYmmddhhmmss.dump。支持日期区间、改备注、下载和删除。备份内容为整个应用数据库，包括 audit 模式。 |
| 服务器状态 | 显示运行版本、负载、cgroup 资源统计、磁盘容量及服务器到当前浏览器的 HTTPS 下载测速。 |

容器统一挂载 `storage/logs`，宿主机路径为 `$HEJI_VAR/logs`。分类包括 laravel、php、nginx、postgres、redis、scheduler、runtime 和 tests。生产运行账号独立于迁移账号，数据库和 Redis 不发布主机端口。部署时为持久卷与备份安排磁盘容量、保留期限和异机副本。

备份下载是单一应用数据库的逻辑归档。恢复通常在新建空库进行，恢复目标需先准备所用数据库角色，使用 PostgreSQL 18 的客户端。以下命令中的 `heji_restore` 为独立的空库，不要填入在线库名。

```bash
docker compose --env-file /dev/null -f compose.setup.yaml run --rm -T --build \
  -v /absolute/path/to/backups:/input:ro ops backup-list /input/example.dump
docker compose --env-file /dev/null -f compose.setup.yaml run --rm -T --build \
  -v /absolute/path/to/backups:/input:ro ops restore /input/example.dump heji_restore
```

图片、TLS 证书、应用密钥、原始日志和归档文件保存在数据库外，应与数据库归档分别备份。恢复后核对网站账号、权限、订单金额与库存、日历及图片，再切换流量。

## 代码入口

| 位置 | 内容 |
| --- | --- |
| routes/web.php、config/heji.php | 域名、路由、权限和业务选项 |
| app/Http、app/Services、app/Support | 控制器、中间件、库存、排期、备份及表格导出 |
| database/schema.sql、database/migrations | 表、外键、检查约束及审计触发器 |
| resources/views、resources/assets、public | Blade 页面、无外部 CDN 的前端资源与站点入口 |
| tests、bin/test、bin/report.php | 功能测试、浏览器测试、方法覆盖门槛与日志 |
| docker、compose*.yaml、bin/release、.github/workflows | 镜像、服务编排与自动发布 |

框架与数据库文档：[Laravel 13 发布说明](https://laravel.com/docs/13.x/releases) · [Laravel 请求伪造防护](https://laravel.com/docs/13.x/csrf) · [PostgreSQL 18 pg_dump](https://www.postgresql.org/docs/18/app-pgdump.html) · [Docker Compose 镜像构建](https://docs.docker.com/reference/compose-file/build/)
