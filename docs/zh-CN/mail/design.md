---
sidebar_label: Mail 设计说明
description: 消息、序列化、SMTP 连接分层；不负责模板与队列策略。
---

# 设计说明 {#design-rationale}

`switon/mail` 将出站邮件保持在应用边界之后：构建消息，交给 mailer，传输留在包内。

## 参见 {#see-also}

- [Mail 包](README.md) — 公开发送路径与配置
- [Mail FAQ](faq.md#mail-faq) — 接线与失败短答

---

## 要解决的问题 {#core-problem}

邮件代码容易漂进传输细节：

- 某服务直接构建 SMTP 载荷
- 另一处重复 MIME 规则
- 第三处自行管理连接复用
- 失败变为原始传输错误

本包将上述工作标准化，使应用代码保留**一条**稳定的邮件边界。

## 首要目标 {#primary-goal}

Switon mail 让发送路径可预测。

- `MailerInterface` 为应用面向的边界
- `Message` 在触及传输细节之前承载邮件意图
- `Rfc822SerializerInterface` 将该意图转为 RFC 5322 与 MIME
- `ConnectionInterface` 将 SMTP 投递与句柄复用藏在边界之后

应用在**一处**发信，传输策略与池化留在组件内。

## 核心选型 {#core-choice}

包内拆分消息结构、序列化与投递。

- 消息对象描述发件人、收件人、头与正文
- 序列化器负责 RFC 5322 细节，如 multipart 输出与 dot-stuffing
- 连接负责 SMTP 传输
- Mailer 串联以上部分并发出生命周期事件

这使常见邮件行为易于测试，而无需向业务代码暴露 curl 或 SMTP 协议细节。

## 取舍 {#trade-off}

Switon 偏好**清晰**的邮件边界，而不是在业务服务里散落传输层控制。

- **好处：** 消息相关代码保持可读、可测。
- **好处：** 池化与传输复用集中管理。
- **代价：** 更低层的 SMTP 调优属于配置或组件代码。

## 刻意保持简单 {#deliberate-simplicity}

本包不试图包揽相邻问题。

- **不**渲染模板
- **不**决定重试或队列策略
- **不**向调用方暴露 SMTP 命令

边界使包聚焦**出站投递**，而非整条邮件流水线。
