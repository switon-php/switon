# Implementation Checklist

用于从 schema 进入实现阶段时的最小验收清单。

## Schema

- [ ] 表与列命名与 `conventions.md` 一致
- [ ] 主索引、唯一约束已定义
- [ ] 应用层完整性校验已覆盖关键 `_id`

## Migration

- [ ] 生成首版 migration（表、列、索引、唯一约束）
- [ ] 迁移脚本支持幂等执行
- [ ] 历史数据的默认值与哨兵值策略明确

## Publishing

- [ ] 发布任务可正确读取并消费 `publish_schedule`
- [ ] 发布事务同时更新 `published_id / published_at / schedule`
- [ ] 前台查询统一走标准可见性规则

## Data Safety

- [ ] 删除内容前处理关联数据（评论、回复、快照、附件引用）
- [ ] 快照链禁止自指并防成环
- [ ] 对外接口不暴露内部自增主键（按接口策略）

## App Mapping

- [ ] `entities.md` 与 schema 字段完全对齐
- [ ] DTO 与 API 输出字段不泄露内部实现细节
- [ ] 回归测试覆盖发布、撤回、回收站、定时发布主路径
