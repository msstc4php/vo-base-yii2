# Changelog

## 0.1.0 - 2026-11-11

First release: new integration, no stability promise until 1.0.

- `Validator\ValueObjectValidator`: validates an attribute through the value object (`valueObjectClass` option);
  refuses `BoundedString`, whose payload would let input choose its own bounds; takes a composite as array or JSON text.
- `Behavior\ValueObjectAttributeBehavior`: maps ActiveRecord attributes to value objects after find/refresh and to
  the shared payload format (JSON for composites) around insert/update, without spurious UPDATEs; checks the map
  against the model's attributes at attach time.
- `Behavior\ValueObjectAttributes`: ActiveRecord trait that restores the value objects after a failed or vetoed
  insert/update.
- Requires `msstc4php/vo-base` `^1.1` and `yiisoft/yii2` `^2.0.55`.
