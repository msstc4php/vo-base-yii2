# Changelog

## [1.0.0] - unreleased

First stable release: from here on a breaking change needs 2.0. What 1.x covers is listed in the README,
"Backward compatibility": the validator, the behavior and the three traits with their public methods, the option
names, the container id, the action parameter binding rules, the translation keys and their placeholders, the stored
formats, and which exception each path throws (the `InvalidConfigException` / `LogicException` split included). Not
covered: message wording otherwise and anything `@internal`.

### Changed

- Requires `msstc4php/vo-base` ^1.4 (was ^1.1): an abstract class implementing `ResolvesConcreteClass` is a valid
  rule class, mapped attribute, model value and action parameter type, and a mapper built from a `ProvidesCodecs` set
  (`new ValueObjectMapper(Codecs::all())`) defined in `Yii::$container` reaches the validator, the behavior, the model
  trait and the action parameters; both are tested.
- `ValueObjectAttributeBehavior::dehydrate()` is public API, paired with `hydrate()`, for writes that skip the save
  events; it was marked `@internal event handler`. `hydrateFromStorage()` stays `@internal`.
- `ValueObjectValidator::resolvedMapper()` is `@internal`: the model trait uses it.
- The private helpers of `ValueObjectActionParams`, `ValueObjectModel` and `ValueObjectAttributes` moved into
  `@internal` classes (`Internal\ActionParamBinder`, `Internal\ModelValues`, `Internal\RecordBehaviors`): a method of
  the same name in your controller or model (`bindValueObject()`, `convertAttribute()`, `restoreValueObjects()`, ...)
  replaced the trait's helper silently. The traits now hold only their public methods.
- README: the validator's `{value}` placeholder is no longer advertised; Yii fills it with the submitted input, so a
  custom message using it repeats the input to the client.

### Upgrading from 0.x

- No change to the rule, the options, the behavior's storage, the traits' public methods, the action parameter
  binding or the messages.
- Update `msstc4php/vo-base` to ^1.4 if it is pinned lower.
- A custom validator message that uses `{value}` still works, but repeats the client's input; drop the placeholder.
- Code that called `ValueObjectValidator::resolvedMapper()` should read the rule's `mapper` option or the container's
  `ValueObjectMapper` instead; the method is not covered by 1.x.
- A controller or model that relied on overriding one of the traits' private helpers (they were never documented)
  no longer changes the trait's behaviour.
- `Internal\DefaultMapper` has a private constructor now; it was `@internal` already.

## 0.3.0 - 2026-11-23

- `Behavior\ValueObjectAttributeBehavior` without a `mapper` option uses `Yii::$container->get(ValueObjectMapper::class)`,
  like the validator, the model trait and the action parameters; before, it used a plain mapper of its own, so a codec
  defined in the container (vo-documents' composite names, an application's own composites) needed a `mapper` option
  on every behavior. Without a container definition every component shares one plain mapper (the validator and the
  action parameters used to build a new one per use); a definition that builds anything else than a
  `ValueObjectMapper` throws `LogicException`.

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
