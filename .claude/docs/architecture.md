# Architecture

Two areas, enforced by `deptrac.yaml` as two layers with no allowed edges between them:

- **`Validator/`** — `ValueObjectValidator`, the request-input path.
- **`Behavior/`** — `ValueObjectAttributeBehavior`, the storage path.

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

| Event | Attributes | Old attributes |
|---|---|---|
| `afterFind`, `afterRefresh` | payload -> value object | the same instance when the old payload is identical |
| `beforeInsert`, `beforeUpdate` | value object (or raw) -> payload | value object -> payload |
| `afterInsert`, `afterUpdate` | payload -> value object | the same instance when identical, else its own hydration |

Why both: `getDirtyAttributes()` compares with `!==`. With old attributes as value objects sharing the current
instance, an untouched attribute is clean outside `save()`; with both sides dehydrated inside `save()`, the comparison
is by payload, so an equal value object assigned anew does not produce an UPDATE.

Null never goes through the mapper, and a null attribute is never written back by the behavior: an attribute left out
of a partial `select()` stays unset, otherwise the next save would write NULL into it.
