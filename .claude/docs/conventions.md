# Conventions

## Exceptions

Misconfiguration is `yii\base\InvalidConfigException` (Yii-idiomatic; built-in validators do the same), not the
mapper's `LogicException`: support is checked before the mapper is ever called with the class, so the mapper's
`LogicException` cannot surface. Input errors are the core's `InvalidValueObjectException` family, unwrapped. The `InvalidConfigException`
(validator, behavior) / `LogicException` (traits, `DefaultMapper`) split is frozen for 1.x (O9, README BC section).

## Traits

A trait holds only public or overriding methods; their bodies live in `Internal\` static classes with a private
constructor. A private trait method would be replaced, silently, by a user method of the same name.
The README promises that no 1.x release adds a method, property or constant to a trait; Roave does not report
additions to a trait, so `testTheTraitHolds*` (one per trait) freezes each trait's method list and asserts it has no
properties or constants.

## `BaseYii`, not `Yii`

`src/` calls `yii\BaseYii::t()` instead of `Yii::t()`: `Yii` lives in `vendor/yiisoft/yii2/Yii.php`, which is not
autoloadable (an application requires it from its entry script), so PHPStan cannot see it. Both share the static
`$app`, so the behavior is the same.

## Tests

- Real Yii: `Tests\Support\YiiApplication` (a trait with `#[Before]`/`#[After]`) builds a `yii\console\Application`
  with an in-memory SQLite `db` per test and requires `Yii.php` once. `YII_ENABLE_ERROR_HANDLER` is defined false
  first, otherwise PHPUnit reports every test as risky for leaked error/exception handlers.
- `Tests\Fixture\PlainProfile` is the same table without the trait (for `hydrate()` by hand). `Profile` uses the
  trait, has a unique index on `email` (to provoke `IntegrityException`) and one rule (`email` via the validator, so
  `insert()`/`update()` defaults to validating is observable).
- `Tests\Fixture\Profile` is the ActiveRecord with one column per kind (string, int, float, string enum, int enum,
  a canonicalising string `HexColor`, composites `IntRange`, `DateRange`, `BoundedString`). `Priority` is a test int-backed enum
  (the core has none); `Size` + `SizeCodec` exercise the `mapper` option.
- Everything lives in `tests/Unit`: Infection runs `--testsuite=unit` only (template config), and SQLite in memory
  is fast enough for that suite. `tests/Integration` is empty, as in the Doctrine package.
- "No UPDATE issued" is asserted through `update()` returning 0: `updateInternal()` returns 0 only when no dirty
  attributes remain (an UPDATE on SQLite reports 1 matched row even when nothing changed).

## Fixtures for the 1.4 core features

`Code` (abstract, `ResolvesConcreteClass`; `ShortCode` up to three letters, `LongCode` four to six) goes through the
validator, `CodeForm` (model trait), `CodeProfile` (the `profile` table with `name` => `Code`, trait included) and
`ProfileController::actionCode()`. `AppCodecs` (`ProvidesCodecs` -> `SizeCodec`) is set as the container singleton
in one test per component; for the action parameters it shows up as the composite `LogicException` on
`actionSize(Size)` (without it Size is not a value-object parameter at all). Redaction tests use the marker
`SeCrEt-7f3a`: the bridge's message is asserted exactly and the previous (or the mapper's own exception) quotes it;
paths documented without a previous assert `getPrevious()` is null.

## Web and model tests

`Tests\Fixture\Web\ProfileController` (trait on `yii\web\Controller`, CSRF off, one action per case, a standalone
`ViewEmailAction`) is driven with `runAction()` under the console application; `Tests\Fixture\ContactForm` is the
form model. `TestCase::run()` is final: the helper is named `runOn()`.
