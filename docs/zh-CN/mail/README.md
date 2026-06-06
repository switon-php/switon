---
sidebar_label: Mail 包
description: switon/mail：MailerInterface、Message 与 SMTP 发送边界。
---

# Mail 包 {#mail-package}

`switon/mail` 将出站邮件收敛到**一条应用边界**：构建消息，交给 mailer，由包内处理 SMTP 与连接复用。适用于应用代码需要发信、但**不应**直接操作 SMTP 命令或池化连接的场景。模板、重试与入队仍在包外。

**关键特性：**

- **统一发送边界：** 注入 `Switon\Mail\MailerInterface`，应用代码不碰 SMTP 细节。
- **流式消息模型：** `Switon\Mail\Message` 涵盖发件人、收件人、正文、附件、头与 Message-ID。
- **SMTP 就绪输出：** `Switon\Mail\Rfc822SerializerInterface` 生成 RFC 5322 与 MIME 载荷并做点转义（dot-stuffing）。
- **可观测投递：** 发送路径发出开始、成功、失败事件。
- **池化传输：** 默认 `Switon\Mail\Mailer` 通过 `Switon\Pooling\PoolManagerInterface` 复用 `Switon\Mail\ConnectionInterface`。

---

## 安装 {#installation}

**环境要求：** PHP 8.3+、`ext-curl`、`switon/core`、`switon/event`、`switon/pool`、`psr/event-dispatcher`

```bash
composer require switon/mail
```

---

## 快速开始 {#quick-start}

绑定 `Switon\Mail\MailerInterface`，注入后发送一封邮件。

```yaml
# switon.yml
Switon\Mail\MailerInterface:
  class: Switon\Mail\Mailer
  host: smtp.example.com
  port: 587
  encryption: starttls
  username: ''
  password: ''
  from: no-reply@example.com
  from_name: Switon
  timeout: 10
  pool_size: 1
  pool_timeout: 1
```

```php
namespace App\Service;

use Switon\Core\Attribute\Autowired;
use Switon\Mail\Address;
use Switon\Mail\MailerInterface;

class NotifyService
{
    #[Autowired] protected MailerInterface $mailer;

    public function sendWelcome(string $email): void
    {
        $message = $this->mailer->compose()
            ->to(new Address($email))
            ->subject('Welcome')
            ->text('Thanks for signing up.');

        $this->mailer->send($message);
    }
}
```

`MailerInterface::compose()` 会预填配置的发件人。若单封邮件需要不同发件人，请直接使用 `Switon\Mail\Message::compose()`。

---

## 核心概念 {#core-concepts}

### 发送边界 {#send-boundary}

`MailerInterface` 是应用侧发送邮件的边界。业务服务依赖该接口，不直接处理 SMTP 命令与池化连接。

### 消息模型 {#message-model}

`Switon\Mail\Message` 为出站邮件构建器。

- 发送前必须调用 `from()`
- `to()`、`cc()`、`bcc()` 追加收件人
- `to` / `cc` / `bcc` 合计至少一名收件人；主题与正文可选
- `text()` 与 `html()` 可同时使用（序列化器生成 `multipart/alternative`）
- `attach()` 添加 MIME 附件，并将消息变为 `multipart/mixed`
- `replyTo()`、`header()`、`messageId()` 可选
- `From`、`To`、`Subject`、`MIME-Version` 等保留 SMTP 头名在序列化时会被跳过
- `Bcc` 收件人留在 SMTP 信封中，不出现在 DATA 头

### 发送路径与事件 {#events}

`Switon\Mail\Mailer::send()` 校验收件人，构建 RFC 5322 载荷，从池中借出 `Switon\Mail\ConnectionInterface` 并投递。发送路径发出三种事件：

- `Switon\Mail\Event\MailSending` — 投递开始前
- `Switon\Mail\Event\MailSent` — 投递成功
- `Switon\Mail\Event\MailFailed` — 失败时，在**重新抛出**原异常之前

### 失败类型 {#failures}

- `Switon\Mail\Exception\InvalidMessageException` — 消息结构问题总类。
- `Switon\Mail\Exception\DeliveryException` — 投递时失败总类；叶子情形含 `ConfigurationException`、`ConnectionException`、`TlsException`、`AuthException`、`ProtocolException`。

---

## 配置 {#configuration}

多数应用只需配置：

- `host` 与 `port` — SMTP 端点
- `encryption` — `none`、`starttls` 或 `ssl`
- `from` — `MailerInterface::compose()` 使用的默认发件人
- `pool_size` — 为本 mailer 保留的池化连接数

常见组合：`587/starttls`、`465/ssl`，或可信中继用 `none`。

### 选项发现 {#option-discovery}

```bash
bash bin/console config:options 'Switon\Mail\MailerInterface'
```

---

## 使用注意 {#keep-in-mind}

- `MailerInterface::compose()` **只**填充发件人；收件人仍须自行添加。
- `Bcc` 在常规发送输出中不可见。
- 突发流量导致池紧张时，优先调大 `pool_size`。
- 若希望 `compose()` 开箱可用，请保持 `from` **非空**。

---

## 下一步 {#next}

- **短答：** [FAQ](faq.md#mail-faq)
- **设计取舍：** [设计说明](design.md#design-rationale)
- **调优连接复用：** [Pool 包](../pool/README.md)
- **异步或重试：** [Queue 包](../queue/README.md)
- **将投递生命周期接入日志或副作用：** [Event 包](../event/README.md)
