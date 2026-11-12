# Internal knowledge base

Engineer notes for `msstc4php/vo-base-yii2`. Team-readable, not user-facing documentation.

| File | What it answers |
|---|---|
| [architecture.md](architecture.md) | The layers (validator, behavior, web, model, internal), the deptrac rule, the attribute life cycle of the behavior |
| [integrations.md](integrations.md) | Which Yii hooks are used and why (validator, behavior, `bindActionParams`, model trait); the mapper contract; storage formats |
| [conventions.md](conventions.md) | Test setup, exception choice, naming choices that look arbitrary |
| [known-issues.md](known-issues.md) | Yii and Composer traps: the `class` key, bower-asset, absent action parameters, typecasting, error handlers, BoundedString |

Related: the core's `.claude/docs/` (payload layer) and `../vo-base-doctrine/.claude/docs/` (same formats).
