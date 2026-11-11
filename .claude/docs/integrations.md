# Integrations

## Yii 2 validator — `Validator\ValueObjectValidator`

- Extends `yii\validators\Validator`; overrides `init()` (config check, default message) and `validateValue()`.
  `validateAttribute()` is the base one, so standalone `$validator->validate($value, $error)` and model rules share
  one path.
- Options: `valueObjectClass` (required), `message`, `mapper`. Yii's `skipOnEmpty` (default true) skips
  `null`/`''`/`[]`; with `skipOnEmpty => false`, `null` and `''` still pass (mirrors the Symfony bundle's validator).
- Config check `supportsUntrusted()`, runtime `fromUntrustedPayload()` (core 72d0fc5).
- Every `InvalidValueObjectException` (including `InvalidPayloadException` for wrong type/shape) becomes the
  validation error. A composite also comes as JSON text (what the behavior accepts on save). Unlike the Symfony validator, a non-string is not an exception: Yii's validators report wrong
  input types as errors (`StringValidator` does), and request data can be arrays.
- Default message `BaseYii::t('yii', '{attribute} is invalid.')` — that string is in Yii's own message catalogue,
  so it is translated in every bundled language.
- `clientValidateAttribute()` is the base one, returning null: no client-side validation.

## Yii 2 ActiveRecord — `Behavior\ValueObjectAttributeBehavior`

- `attach()` rejects a non-`BaseActiveRecord` owner and any map entry whose class `ValueObjectMapper::supports()`
  denies, both with `yii\base\InvalidConfigException`. Behaviors are instantiated per model instance, so this runs
  per instance; it is a few `is_subclass_of` calls.
- Works with any `BaseActiveRecord`; the JSON-column check (`phpType === 'array'`) needs a `yii\db\ActiveRecord`
  table schema, any other AR (MongoDB, Redis) gets JSON text for composites.
- Trust: database reads use `ValueObjectMapper::fromPayload()`; a raw (non-instance) attribute on save is user
  input (`load()`/`setAttributes()`), so it goes through `supportsUntrusted()` + `fromUntrustedPayload()` and a class
  whose payload carries its own constraints (BoundedString) is refused with `InvalidPayloadException`.
- Composite detection is the mapper's `isComposite()`: a string for a composite class is JSON text and is decoded
  first (`Internal\CompositeJson`); a JSON scalar (`5`) is `InvalidPayloadException`. Invalid JSON becomes `InvalidPayloadException` with the `JsonException` as
  previous.
- `attach()` also checks `$owner->hasAttribute($name)` (a typo is `InvalidConfigException`). For a db ActiveRecord
  that loads the table schema at attach time, which `__set` would do anyway.
- One default `ValueObjectMapper` is shared (static) by every behavior and validator without an own `mapper`.
- Failed saves: the `ValueObjectAttributes` trait wraps `insert()`/`update()` in `try`/`finally` and calls each
  attached behavior's `hydrate()`. A trait cannot be a `#[CoversClass]` target: tests use `#[CoversTrait]`, which
  exists in PHPUnit 11 to 13.
- JSON is written with `json_encode(..., JSON_THROW_ON_ERROR)` (a `JsonException`, e.g. invalid UTF-8 in a
  `BoundedString`, becomes `InvalidPayloadException` with it as previous) — the same flags as the Doctrine package's
  `AbstractJsonVoType`, so the bytes match.

## Storage formats

Identical to every other bridge (the core's mapper): string, int, float, enum `->value`, composite JSON object.
