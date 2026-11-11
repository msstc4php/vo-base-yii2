# Integrations

## Yii 2 validator — `Validator\ValueObjectValidator`

- Extends `yii\validators\Validator`; overrides `init()` (config check, default message) and `validateValue()`.
  `validateAttribute()` is the base one, so standalone `$validator->validate($value, $error)` and model rules share
  one path.
- Options: `valueObjectClass` (required), `message`, `mapper`. Yii's `skipOnEmpty` (default true) skips
  `null`/`''`/`[]`; with `skipOnEmpty => false`, `null` and `''` still pass (mirrors the Symfony bundle's validator).
- Config check `supportsUntrusted()`, runtime `fromUntrustedPayload()` (core 72d0fc5).
- Every `InvalidValueObjectException` (including `InvalidPayloadException` for wrong type/shape) becomes the
  validation error. Unlike the Symfony validator, a non-string is not an exception: Yii's validators report wrong
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
- Composite detection uses the mapper's `isScalarBacked()` plus a `BackedEnum` check: anything else holding a string
  is JSON text and is decoded first. Invalid JSON becomes `InvalidPayloadException` with the `JsonException` as
  previous.
- JSON is written with `json_encode(..., JSON_THROW_ON_ERROR)` — the same flags as the Doctrine package's
  `AbstractJsonVoType`, so the bytes match.

## Storage formats

Identical to every other bridge (the core's mapper): string, int, float, enum `->value`, composite JSON object.
