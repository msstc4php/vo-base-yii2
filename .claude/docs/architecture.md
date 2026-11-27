# Architecture

Five public layers in `deptrac.yaml` plus `Internal` and the two trait-body layers; `Validator` and `Behavior` never see each other, `Web` and `Behavior` neither; `Internal` is usable by all, `Model` also reads `Validator`:

- **`Validator/`** — `ValueObjectValidator`, the request-input path.
- **`Behavior/`** — `ValueObjectAttributeBehavior` (the storage path) and the `ValueObjectAttributes` trait for the
  model (restores value objects after a failed/vetoed save).
- **`Web/`** — `ValueObjectActionParams`, controller trait (route/query parameter -> typed action argument).
- **`Model/`** — `ValueObjectModel`, model trait reading validated attributes as value objects; the only consumer of
  `Validator` (it takes the class and mapper from the rule).
- **`Internal/`** (all `@internal`) — `ActionParamBinder`, `ModelValues` and `RecordBehaviors` hold the bodies of the
  three traits (a private trait helper is silently replaced by a user method of the same name, so the traits keep only
  their public/overriding methods; adding a method to a trait in a minor is excluded by the README BC section).
  `ModelValues` (deptrac layer `ModelInternal`: Internal + Validator) and `RecordBehaviors` (`BehaviorInternal`:
  Behavior) are separate deptrac layers, collected by class name, so the plain `Internal` layer still sees no public
  area. `DefaultMapper` (container mapper or a shared fallback), `TypeLabel` (`@internal`, "email address" from `EmailAddress`; same algorithm as the Laravel
  rule's `typeLabel()`, one copy per package) and `CompositeJson` (`@internal`): decode/encode of a composite's JSON text, so the validator accepts
  exactly what the behavior accepts on save, and both report JSON errors as `InvalidPayloadException`.

The core and Yii are not declared as layers, so they do not appear in the ruleset. Both areas call the core's
`Payload\ValueObjectMapper` and nothing else decides a value object's kind or storage form. The one exception is
JSON: composites are stored as JSON text, and JSON is the bridge's job.

## Trust boundary

The validator reads untrusted input, the behavior's read path reads the database (trusted). `BoundedString`'s
payload carries its own `minLength`/`maxLength`, so:

- the validator checks `ValueObjectMapper::supportsUntrusted()` at `init()` (`InvalidConfigException` for
  `BoundedString`) and calls `fromUntrustedPayload()` at runtime;
- the behavior decodes the database with `fromPayload()`, but on save treats a raw (non-instance) attribute as user
  input: `supportsUntrusted()` + `fromUntrustedPayload()`, so a raw array/JSON/string for `BoundedString` put there
  by `load()`/`setAttributes()` throws `InvalidPayloadException`. No class name is hard-coded in the bridge.

## The behavior's attribute life cycle

| Event | Handler | Attributes | Old attributes |
|---|---|---|---|
| `afterFind`, `afterRefresh` | `hydrateFromStorage()` | payload -> value object (`fromPayload`, trusted) | the same instance when the old payload is identical |
| `beforeInsert`, `beforeUpdate` | `dehydrate()` | value object (or raw, untrusted) -> payload, recorded in `$written` | value object -> payload, recorded in `$writtenOld` |
| `afterInsert`, `afterUpdate`, trait `finally` | `hydrate()` | the recorded instance back where the payload is still in place | the current attribute's instance when its payload matches, else the recorded old instance |

`dehydrate()` and `hydrate()` are the documented public pair (1.0, O5): users call them around writes that skip the
save events; `hydrateFromStorage()` is `@internal`. `hydrate()` never calls the mapper: it puts back the exact instances `dehydrate()` replaced, so it cannot fail, is
idempotent (the records are cleared), and never turns rejected raw input into a value object (an attribute whose
raw value was refused was never recorded). `dehydrate()` starts with `hydrate()`, so a second save after a vetoed
one without the trait still works.

Why both: `getDirtyAttributes()` compares with `!==`. With old attributes as value objects sharing the current
instance, an untouched attribute is clean outside `save()`; with both sides dehydrated inside `save()`, the comparison
is by payload, so an equal value object assigned anew does not produce an UPDATE.

Null never goes through the mapper, and a null attribute is never written back by the behavior: an attribute left out
of a partial `select()` stays unset, otherwise the next save would write NULL into it.
