---
description: 在已工作的业务事件上发送欢迎、重置、回执或审批类邮件，且不过早把 SMTP、队列时机或模板渲染塞进控制器。
---

# 发送事务性邮件 {#send-transactional-email}

当一项已工作的业务事件现在应发送欢迎、重置、回执或审批类邮件，且不宜过早把 SMTP 细节、队列时机或模板渲染变成控制器逻辑时，使用本指南。

## 选择首条邮件投递边界 {#delivery-boundary}

从与真实投递问题匹配的边界出发。

| 边界 | 最适合 | 注意 |
|---|---|---|
| [Mail](../mail/README.md) | 业务动作成功后，一条应用 service 即可立即发送 | 当请求延迟可接受时，欢迎、回执或审批类首封邮件的默认首选 |
| [Queue](../queue/README.md) | SMTP 延迟、重试或进程隔离比立即完成更重要 | 当用户面向的请求应在不等待 SMTP 的情况下结束时使用；邮件 service 不变，只改生产者到 worker 的交接 |
| [Schedule](../schedule/README.md) | 邮件应由时间规则触发，如提醒或跟进投递 | schedule 决定**何时**触发工作；不替代邮件发送边界 |
| [添加认证](add-authentication.md) | 邮件来自注册、登录恢复或受保护账户流程 | 将身份、令牌与访问规则与出站邮件边界分开 |

一种常见的应用形态是：

- **应用 service** 决定一项业务事件是否应通知用户
- **模板渲染器** 用应用数据构建 subject、text 与 HTML
- **Mail** 组装并发送消息
- **Queue** 仅当投递应离开请求路径时
- **Schedule** 仅当由时间而非原始事件触发邮件时

## 推荐构建顺序 {#build-order}

### 1. 从一个能清楚命名的业务事件开始 {#build-event}

选定一个事件，如「用户已注册」「已请求密码重置」或「订单已支付」。
首条邮件路径应在讨论传输之前，用一句话说明*为何存在这封邮件*。

### 2. 在事件与 SMTP 之间保持一条应用邮件 service {#build-service}

不要让控制器、队列 job 或定时任务临时拼装邮件。
保持一条如 `WelcomeEmailServiceInterface` 或 `ReceiptMailerInterface` 的 service，使同一邮件意图可内联、在 worker 中或按时间规则执行。

### 3. 在组装传输细节之前用应用数据渲染消息 {#build-render}

模板渲染应将领域数据变为 `subject`、`text` 与可选的 `html`。
再由 `MailerInterface` 将渲染结果变为 `Message`。

这样链接生成、令牌选择与文案留在应用代码中，而不会散落在各处 SMTP 调用里。

### 4. 仅在失败窗口与用户预期一致时选择内联发送 {#build-vs-queue}

当以下三者同时为真时，保持请求内投递：
- 用户可等待 SMTP 往返
- 发送失败应使同一请求或命令失败
- 尚不需要持久化交接

当注册、结账或其他用户面向路径应在不等待 SMTP 成功的情况下完成时，迁到 [Queue](../queue/README.md)。

### 5. 增加一条可见的成功路径与一条可见的失败路径 {#build-visibility}

在扩大投递规模之前，确认两点：
- 一条真实或测试路径能到达 `MailSent`
- 一条错误配置或投递失败能到达可见日志或失败表面

若尚未观察 `MailSent`，先加一个小型监听器或日志钩子。见 [Mail 事件](../mail/README.md#events)、[Mail 失败](../mail/README.md#failures)、[Event](../event/README.md) 与 [Log](../log/README.md#quick-start)。

首条可信的邮件路径需要可观测性，然后才需要多个提供商。

### 6. 将提醒或跟进作为独立的时间边界 {#build-schedule}

若邮件应在明天、每周或过期窗口后发出，将触发放在 [Schedule](../schedule/README.md) 或 [添加周期性任务](add-recurring-work.md#add-recurring-work) 中。
不要用未来投递逻辑压垮原始注册或结账请求。

### 7. 在营销活动或提供商平台设计之前停下 {#build-stop}

首条事务性邮件的成功**不**需要批量受众逻辑、提供商故障转移或营销自动化。
在扩大系统之前，先让一条由业务触发的邮件路径可信。

## 最小接线 {#minimal-wiring}

下面只用短代码展示应用数据、渲染与投递之间的接缝。

下文中的 `WelcomeEmailServiceInterface`、`WelcomeEmailTemplateInterface`、`RegistrationService` 等应用级命名仅为示例；稳定部分是 Switon 邮件边界及其周围可选的队列/schedule 交接。

### 保持 mailer 配置小而明确 {#wiring-config}

从 `switon.yml` 中常规的 SMTP 边界开始：

```yaml
# switon.yml
Switon\Mail\MailerInterface:
  class: Switon\Mail\Mailer
  host: smtp.example.com
  port: 587
  encryption: starttls
  username: ${MAIL_USERNAME:-}
  password: ${MAIL_PASSWORD:-}
  from: no-reply@example.com
  from_name: Example App
```

更深的 SMTP 或连接池调优见组件指南：[Mail](../mail/README.md#quick-start)。

### 将渲染与发送放在一条应用 service 之后 {#wiring-service}

为业务邮件定义一条应用自有边界：

```php
namespace App\Service;

interface WelcomeEmailServiceInterface
{
    public function sendForUser(int $userId): void;
}
```

将模板渲染与传输组装分开：

```php
namespace App\Mail;

use App\Entity\User;

interface WelcomeEmailTemplateInterface
{
    /** @return array{subject:string,text:string,html:string} */
    public function render(User $user): array;
}
```

再由一条 service 加载应用数据、渲染内容并发送消息：

```php
namespace App\Service;

use App\Mail\WelcomeEmailTemplateInterface;
use App\Repository\UserRepository;
use Switon\Core\Attribute\Autowired;
use Switon\Mail\Address;
use Switon\Mail\MailerInterface;

final class WelcomeEmailService implements WelcomeEmailServiceInterface
{
    #[Autowired] protected UserRepository $userRepository;
    #[Autowired] protected WelcomeEmailTemplateInterface $template;
    #[Autowired] protected MailerInterface $mailer;

    public function sendForUser(int $userId): void
    {
        $user = $this->userRepository->find($userId);

        if ($user === null) {
            return;
        }

        $body = $this->template->render($user);

        $message = $this->mailer->compose()
            ->to(new Address($user->email, $user->name))
            ->subject($body['subject'])
            ->text($body['text'])
            ->html($body['html']);

        $this->mailer->send($message);
    }
}
```

`Switon\Mail\Address` 是邮件包中单个收件地址的值对象，因此 `use Switon\Mail\Address;` 属于常规 `switon/mail` API，而非应用本地辅助类。

职责保持清晰：

- 仓储决定**加载哪些应用数据**
- 模板决定**用户应读什么**
- `MailerInterface` 决定**消息如何投递**

### 保持首条生产者路径精简 {#wiring-inline}

当请求延迟与失败耦合可接受时，在业务动作成功后调用邮件 service：

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;

final class RegistrationService
{
    #[Autowired] protected WelcomeEmailServiceInterface $welcomeEmail;

    public function completeSignup(int $userId): void
    {
        // persist the user first...
        $this->welcomeEmail->sendForUser($userId);
    }
}
```

当应用仍需在加入 worker 之前证明一条清晰的邮件结果时，这是最佳首条路径。

### 当投递应离开请求时，将同一 service 放在队列传输之后 {#wiring-queue}

当注册或结账应在不等待 SMTP 的情况下成功时，保持邮件 service，只改交接。
此处的「生产者到 worker 交接」指：应用 service 现在将一条邮件 job 入队，独立 worker 进程稍后发送邮件：

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Queue\QueueEnvelope;
use Switon\Queue\QueueInterface;

final class RegistrationService
{
    #[Autowired] protected QueueInterface $queue;

    public function completeSignup(int $userId): void
    {
        // persist the user first...
        $this->queue->push('welcome-emails', QueueEnvelope::of(['userId' => $userId]));
    }
}
```

`QueueEnvelope::of([...])` 来自 `switon/queue` 的小型工厂方法：根据传入数组创建信封并填充 `body` 载荷；队列名、消息 id 与时间戳由队列传输行为稍后补充。

```php
namespace App\Job;

use App\Service\WelcomeEmailServiceInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\RunnerInterface;
use Switon\Queue\Attribute\Queued;
use Switon\Queue\QueueEnvelope;

#[Queued('welcome-emails')]
final class SendWelcomeEmailJob implements RunnerInterface
{
    #[Autowired] protected WelcomeEmailServiceInterface $welcomeEmail;

    public function run(mixed $payload): mixed
    {
        /** @var QueueEnvelope $envelope */
        $envelope = $payload;

        $this->welcomeEmail->sendForUser((int) ($envelope->body['userId'] ?? 0));

        return null;
    }
}
```

应用边界保持稳定：

- 生产者决定**何时将一项业务事件变为邮件工作**
- 队列决定**工作如何被持久交接**
- 同一邮件 service 仍决定**发送什么**

对此队列变体，不要停在 job 类本身。还需要 [添加持久化后台工作](add-durable-background-work.md#add-durable-background-work) 中的 Redis 队列设置，尤其是 `switon.yml` 中的 `Switon\Redis\ClientInterface`、`Switon\Queue\QueueInterface` 与 job 发现路径。

### 在原始事件路径之外添加基于时间的提醒 {#wiring-schedule}

对跟进或提醒，保持同一邮件 service，用定时任务包装，而非在原始请求中嵌入时间逻辑。
当触发已是时钟而非原始业务事件时，见 [添加周期性任务](add-recurring-work.md#add-recurring-work)。

## 首条路径上宜避免什么 {#pitfalls}

有意让首条事务性邮件路径保持窄。

下列内容不宜过早加入：

- **在控制器、job 或 task 内渲染邮件正文** — 在 `MailerInterface` 之前保持一条应用自有渲染边界
- **意外将业务成功与 SMTP 耦合** — 若请求延迟或投递失败不应阻塞用户路径，迁到 [Queue](../queue/README.md)
- **让 `mail` 决定令牌或链接模型** — 重置令牌、签名 URL 与账户策略属于应用代码
- **把定时提醒当作延迟的请求逻辑** — 当时间才是真实触发器时使用 [Schedule](../schedule/README.md)
- **在一条邮件路径可信之前设计多提供商故障转移、营销活动或分析** — 先让一条欢迎、重置或回执路径可观测
- **忽略无效消息与投递问题之间的失败分界** — 调用方错误与 SMTP/运行时失败是不同边界

完成首条事务性邮件路径后，初学者应能感到：

> 我知道业务触发的邮件应放在哪、内容如何渲染，以及投递何时应留在请求内或迁到后台。

## 继续深入 {#go-deeper}

- **从注册、恢复或受保护账户流程发送：** [添加认证](add-authentication.md)
- **在请求内投递并深入 SMTP、消息构建与失败：** [Mail](../mail/README.md)
- **将同一路径交给持久 worker：** [添加持久化后台工作](add-durable-background-work.md#add-durable-background-work) · [Queue](../queue/README.md)
- **按时间规则触发提醒或跟进：** [添加周期性任务](add-recurring-work.md#add-recurring-work) · [Schedule](../schedule/README.md)
- **观察发送生命周期或失败钩子：** [Event](../event/README.md) · [Log](../log/README.md)
- **在扩大系统之前保护首条产生邮件的路径：** [为已有应用添加测试](add-testing-to-a-working-app.md#add-testing-to-a-working-app)
