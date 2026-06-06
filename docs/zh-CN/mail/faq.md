---
sidebar_label: Mail FAQ
description: Mail：Bcc、加密、事件与 InvalidMessageException/DeliveryException。
---

# Mail FAQ {#mail-faq}

**消息结构、SMTP 配置与投递失败相关的短答。**

> **阅读前提：** [Mail 包](README.md)

## 入门 {#getting-started}

### 怎么发邮件？ {#send-mail}

注入 `Switon\Mail\MailerInterface`，调用 `compose()`，添加收件人，再调用 `send()`。见 [快速开始](README.md#quick-start)。

### `MailerInterface::compose()` 还是 `Message::compose()`？ {#compose-choice}

配置的默认发件人正确时用 `MailerInterface::compose()`。单封邮件需要不同发件人时用 `Switon\Mail\Message::compose()`。

### `send()` 前必填什么？ {#required-fields}

`from()`，以及 `to()`、`cc()`、`bcc()` 中**至少一名**收件人。主题与正文可选。

### 能只填 `bcc()` 吗？ {#bcc-only}

可以，只要已设置 `from()`。SMTP 信封仍包含收件人；没有可见 `To` 或 `Cc` 时，DATA 使用 `To: undisclosed-recipients:;`。

### 能同时发纯文本和 HTML 吗？ {#html-and-text}

可以。同时设置 `text()` 与 `html()`，序列化器生成 `multipart/alternative`。

### 怎么加附件？ {#attachments}

对 `attach()` 传入 `Switon\Mail\AttachmentInterface` 值。任意附件都会将消息变为 `multipart/mixed` 输出。

## 配置 {#configuration}

### SMTP 在哪配？ {#where-configure}

在 `switon.yml` 绑定 `Switon\Mail\MailerInterface`。见 [配置](README.md#configuration)。

### 合法 `encryption` 值？ {#valid-encryption}

`none`、`starttls` 或 `ssl`。

### 一定要 SMTP 认证吗？ {#smtp-auth}

不必。`username` 为空则跳过 AUTH。

### 怎么看可配置项？ {#discover-options}

执行 `bash bin/console config:options 'Switon\Mail\MailerInterface'`。

## 投递 {#delivery}

### 为什么 Bcc 不在消息头里？ {#bcc-headers}

这是常规发送路径：`Bcc` 留在 SMTP 信封中，不出现在 DATA 头。

### 应先捕获哪种异常？ {#which-exception}

消息结构问题捕获 `Switon\Mail\Exception\InvalidMessageException`，SMTP 失败捕获 `Switon\Mail\Exception\DeliveryException`。叶子投递失败含 `ConfigurationException`、`ConnectionException`、`TlsException`、`AuthException`、`ProtocolException`。

### 发送周围有事件吗？ {#mail-events}

有：`Switon\Mail\Event\MailSending` 在投递前；`Switon\Mail\Event\MailSent` 在成功时；`Switon\Mail\Event\MailFailed` 在失败时于重抛原异常**之前**触发。

## 排错 {#troubleshooting}

### 加密相关失败 {#encryption-error}

先检查 `encryption`，再使端口与之匹配（通常为 `587/starttls` 或 `465/ssl`）。

### 自定义头丢失 {#missing-header}

`From`、`To`、`Subject`、`MIME-Version` 等保留 SMTP 头名会被序列化器跳过。

### 突发发送变慢 {#burst-slowdown}

在拉长 `pool_timeout` 之前先增大 `pool_size`。

### 需要重试或延迟发送？ {#retries-and-background}

不够。将 `mail` 作为 SMTP 层，与 `queue` 或 `schedule` 配合使用。

### 已配 `from` 仍无法正常发送？ {#compose-from}

`compose()` 需要**非空**发件人。空字符串仍会产生缺少可用 `from` 的消息，`send()` 时会抛出 `InvalidMessageException`。
