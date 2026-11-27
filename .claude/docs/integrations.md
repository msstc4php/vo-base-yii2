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
- No class name reaches a validation message. Yii fills `{attribute}` (the label) and `{value}` (the input) itself;
  the README tells users not to use `{value}` (it repeats the input, O3). The validator was left unchanged (no
  `'value' => ''` override): the ruling asked only for the README change.
- Default message `BaseYii::t('yii', '{attribute} is invalid.')` — that string is in Yii's own message catalogue,
  so it is translated in every bundled language.
- `clientValidateAttribute()` is the base one, returning null: no client-side validation.

## Yii 2 controller — `Web\ValueObjectActionParams`

- Overrides the public `web\Controller::bindActionParams($action, $params)`: reflects `InlineAction::$actionMethod`
  on the controller, or `run()` on a standalone action, converts the entries of `$params` whose parameter has a single
  named, non-builtin type the mapper supports, then calls `parent::bindActionParams()`. `EVENT_BEFORE_ACTION` cannot
  change arguments, hence the override.
- Yii's own binder (2.0.55 .. current) passes a class-typed parameter's scalar through unchanged (PHP then throws a
  `TypeError`) and an object through untouched; a parameter absent from `$params` with a non-builtin type goes to
  `bindInjectedParams()` (DI) before defaults and the missing-parameter list are consulted.
- Input goes through `fromUntrustedPayload()`; invalid, including arrays, is `NotFoundHttpException`. The message uses
  `Internal\TypeLabel`. Nullable: `null`/`''` -> `null`; non-nullable `''`/`null` -> 404.
- Supported but unbindable (`!supportsUntrusted()` or `isComposite()`): `LogicException` naming the parameter, present
  or absent. Only a present instance passes through before that check.
- The mapper is resolved once per `bindActionParams()` call. The body is `Internal\ActionParamBinder::bind()`; the
  trait only reflects the action method.
- Mapper: `BaseYii::$container->get(ValueObjectMapper::class)`.

## Yii 2 model — `Model\ValueObjectModel`

- The trait delegates to `Internal\ModelValues::one()` / `all()`, which read the model through its public API only.

- `valueObject($attribute)`: `hasErrors()` -> `LogicException`; the attribute's first active `ValueObjectValidator`
  (`getActiveValidators($attribute)`, so scenario-aware) gives `valueObjectClass` and `mapper`, else the container's
  mapper. `valueObjects()` walks every active `ValueObjectValidator` and its `getAttributeNames()`.
- Value read through `getAttributes([$attribute])`; null/`''` -> null; instance -> as is; a composite string is decoded
  with `Internal\CompositeJson`; `InvalidValueObjectException` -> `LogicException` ("was validate() called?").
- Returning a model with errors from a REST action gives 422 (`rest\Serializer::serializeModelErrors`).

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
- The first `hydrateFromStorage()`/`dehydrate()` checks `$owner->hasAttribute($name)` once per behavior (a typo is
  `InvalidConfigException`); not at attach, which would load the schema for every new model.
- The trait also overrides `updateAttributes()`, which bypasses the save events.
- One default `ValueObjectMapper` is shared (static) by every behavior without an own `mapper` (validators use the container's).
- Failed saves: the `ValueObjectAttributes` trait wraps `insert()`/`update()` in `try`/`finally` and calls each
  attached behavior's `hydrate()`. A trait cannot be a `#[CoversClass]` target: tests use `#[CoversTrait]`, which
  exists in PHPUnit 11 to 13.
- JSON is written with `json_encode(..., JSON_THROW_ON_ERROR)` (a `JsonException`, e.g. invalid UTF-8 in a
  `BoundedString`, becomes `InvalidPayloadException` with it as previous) — the same flags as the Doctrine package's
  `AbstractJsonVoType`, so the bytes match.

## Storage formats

Identical to every other bridge (the core's mapper): string, int, float, enum `->value`, composite JSON object.
