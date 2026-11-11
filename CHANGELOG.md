# Changelog

## 0.1.0 - 2026-11-11

First release: new integration, no stability promise until 1.0.

- `Validator\ValueObjectValidator`: validates an attribute through the value object (`valueObjectClass` option);
  refuses `BoundedString`, whose payload would let input choose its own bounds.
- `Behavior\ValueObjectAttributeBehavior`: maps ActiveRecord attributes to value objects after find/refresh and to
  the shared payload format (JSON for composites) around insert/update, without spurious UPDATEs.
- Requires `msstc4php/vo-base` `^1.1` and `yiisoft/yii2` `^2.0.55`.
