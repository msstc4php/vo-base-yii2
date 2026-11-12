# Changelog

## 0.2.0 - 2026-11-12

- `Web\ValueObjectActionParams`: controller trait that binds action parameters typed with a scalar-backed value
  object or a backed enum (inline and standalone actions); invalid input is a 404, a nullable parameter takes a
  missing, `null` or `''` value as `null`, instances pass through, union types are left alone; a parameter typed with
  a trusted-source-only or composite class is a `LogicException`.
- `Model\ValueObjectModel`: model trait with `valueObject($attribute)` and `valueObjects()` that read the validated
  attributes as value objects through their `ValueObjectValidator` rule; a `LogicException` while the model has
  errors or for an attribute without the rule.
- Existing 0.1.0 APIs are unchanged.

## 0.1.0 - 2026-11-11

First release: new integration, no stability promise until 1.0.

- `Validator\ValueObjectValidator`: validates an attribute through the value object (`valueObjectClass` option);
  refuses `BoundedString`, whose payload would let input choose its own bounds; takes a composite as array or JSON text.
- `Behavior\ValueObjectAttributeBehavior`: maps ActiveRecord attributes to value objects after find/refresh and to
  the shared payload format (JSON for composites) around insert/update, without spurious UPDATEs; checks the map
  against the model's attributes at first use.
- `Behavior\ValueObjectAttributes`: ActiveRecord trait that restores the value objects after a failed or vetoed
  insert/update and handles `updateAttributes()`, which skips the save events.
- Requires `msstc4php/vo-base` `^1.1` and `yiisoft/yii2` `^2.0.55`.
