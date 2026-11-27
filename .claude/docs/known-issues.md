# Known issues

Yii and Composer traps hit while building this package.

## A rule's `class` key is taken

`Validator::createValidator()` sets `$params['class'] = $type` for a class-name rule, overwriting a `class` option
given in the rule. So the option is `valueObjectClass`; a rule written with `'class' => EmailAddress::class` ends up
with no value object class and `init()` throws `InvalidConfigException` ("null given"). A test pins this.

## bower-asset: `composer.json` alone does not install

`yiisoft/yii2` requires `bower-asset/jquery`, `inputmask`, `punycode`, `yii2-pjax`, which are not on Packagist.
Applications solve it with asset-packagist or the asset plugin. Here:

- `composer-ci.json` and `composer-local.json` declare `"replace": {"bower-asset/*": "*"}` for the four packages.
  The library never touches assets, so standing them in is safe for development.
- `composer.json` (the published manifest) does **not**: `replace`/`provide` of a dependency apply to the consumer's
  whole resolution, so it would stop a consumer's application from installing jQuery & co. and silently break its
  asset bundles. A `repositories` entry would not leak (only the root's repositories count) but would make every CI
  install depend on asset-packagist being up, for files nobody uses.
- Therefore every CI job installs `composer-ci.json` (the workflow sets `COMPOSER` globally), and there is no
  "install `composer.json` alone" job, unlike the Doctrine package's `minimal` job. A
  `composer update --dry-run` of `composer.json` alone fails on `bower-asset/jquery` (verified 2026-11-11 UTC).

## Lower bound is 2.0.55, not 2.0.52

2.0.52 is the first release claiming PHP 8.4 support, but `roave/security-advisories` conflicts with
`yiisoft/yii2 <2.0.55` and Composer itself refuses 2.0.52 (advisory `PKSA-mxtc-f5ct-dqqd`, Composer's default
`policy.advisories.block`). So a `--prefer-lowest` install resolves to 2.0.55 anyway; declaring `^2.0.52` would
promise versions nobody can install by default or test. Verified: the suite passes on 2.0.55 with
`--prefer-lowest --prefer-stable` (PHPUnit 11.5) and with the highest set (PHPUnit 13).

## `yiisoft/yii2-composer` plugin is disabled

`allow-plugins` has it `false`: it only writes `vendor/yiisoft/extensions.php` for Yii extension bootstrapping,
which a library's test suite does not need.

## Yii typecasts columns before the behavior sees them

`yii\db\ActiveRecord::populateRecord()` runs `ColumnSchema::phpTypecast()`: an integer column holding the text
`abc` arrives as `0`, so an invalid stored int surfaces as the value object's own exception (e.g.
`InvalidPositiveIntException` for `0`), not as `InvalidPayloadException`. Still loud, just a different class.

## JSON columns on MySQL/PostgreSQL

Yii maps them to `phpType === 'array'`, decodes on read and wraps an array in `JsonExpression` on write; a JSON
**string** there would be double-encoded. Hence the behavior hands such a column the payload array, and accepts an
array on read. SQLite has no JSON column type in Yii, so the test flips `phpType` on the cached schema and calls the
handlers directly rather than round-tripping. PostgreSQL `jsonb` may reorder keys; Yii's dirty check sorts
associative arrays before comparing, so that does not cause a spurious UPDATE.

## The core's payload parsing is strict

Since core 72d0fc5: int payloads must be canonical digits (`"080"` is rejected, by the validator too), float
payloads plain decimals, `DateRange` ISO 8601 with offset only. Form input like `"080"` is therefore a validation
error, not silently 80.

## A failed or vetoed save skips afterSave

`beforeSave` dehydrates; `afterSave` re-hydrates. When the INSERT/UPDATE throws (`IntegrityException`) or a later
`beforeSave` handler sets `isValid = false`, `afterSave` never runs, so the model would keep the payloads. Fixed by
the `ValueObjectAttributes` trait (`try`/`finally` around `insert()`/`update()`); without it, call the behavior's
`hydrate()`. Covered by veto and unique-index tests.

## Event order and `changedAttributes`

The behavior's handlers run before any handler added with `on()` (behaviors attach first). `beforeSave` handlers
after it see payloads; `afterSave` handlers see value objects, but `AfterSaveEvent::$changedAttributes` carries the
old **payloads** (old attributes are dehydrated too, so the dirty comparison is by value).

## bc-check needs the base tag's composer-ci.json

Roave installs the base version via `Composer\Factory::createComposer(..., null, ..., $path)`, and a null config
means `Factory::getComposerFile()`, i.e. `$COMPOSER` relative to the checkout. With `COMPOSER=composer-ci.json`
(workflow env, repeated on the step to make the dependency visible) the tag's `composer-ci.json`, which has the
bower-asset stand-ins, is used. Unexercised until the first tag: the job exits early without one.

## The attribute-name check runs at first use, not at attach

Checking `$owner->hasAttribute()` in `attach()` loaded the table schema for every `new Model()` and every
`instantiate()` during a find, and threw for a model built before its table exists (a migration, a form-only use).
So a typo in `attributes` surfaces as `InvalidConfigException` at the first `hydrateFromStorage()`/`dehydrate()`
(find, refresh, save, `updateAttributes()` via the trait). Class support is still checked at attach.

## `updateAttributes()` skips the save events

`BaseActiveRecord::updateAttributes()` calls `updateAll()` without `beforeSave`/`afterSave`, so the behavior never
dehydrates and value objects would reach the query. The `ValueObjectAttributes` trait overrides it: assigns the
name => value pairs, dehydrates, calls the parent with names only, restores in `finally`. Without the trait, use
`update(false, [...names])` instead, or call the behavior's `dehydrate()` before and `hydrate()` after.

## A BoundedString as a safe attribute

`load($post)` can put an array into a safe `BoundedString` attribute; the save then throws `InvalidPayloadException`
(by design: raw input would choose its bounds). Keep such attributes unsafe and assign instances.

## `getDirtyAttributes()` compares instances outside `save()`

Outside `save()` an equal value object assigned anew shows as dirty (Yii compares with `!==`); `save()` itself
compares payloads and writes nothing. `isAttributeChanged()` has the same instance semantics.

## An absent value-object action parameter is a 500 in Yii itself

`web\Controller::bindActionParams()` sends a parameter that is absent from `$params` and has a non-builtin type to
`bindInjectedParams()`, which throws and becomes `ServerErrorHttpException` ("Could not load required service") for a
non-nullable value object; the "Missing required parameters" 400 is only for builtin types. `ValueObjectActionParams`
therefore throws the 400 itself, listing every missing parameter (value objects and builtin ones; Yii's own check
never runs after it), applies a declared default, and sets an absent nullable one to `null` directly.

## Rector adds `array` to the `bindActionParams` override

`StrictArrayParamDimFetchRector` types a parameter that is used as `$params[$name]`; `web\Controller::bindActionParams()`
has no native parameter types, so `array $params` is an incompatible override (fatal at load). The trait keeps the
parameters untyped and does the array work in `Internal\ActionParamBinder::bind()`, which has a typed signature.

## Every invalid action parameter is a 404

Arrays (`?email[]=x`) and `''` on a non-nullable parameter are 404 like any malformed value, not 400. Symfony parity:
the route does not exist for that value. The `InvalidValueObjectException` is chained as the exception's previous.

## `[]` passes a rule and `when` skips it

`Validator::isEmpty()` treats `[]` as empty, so with the default `skipOnEmpty` the attribute passes `validate()` unchecked,
as does one whose `when` is false. `ValueObjectModel` therefore returns `null` for such attributes instead of converting
(converting would throw a LogicException, a 500 on client input).

## One mapper source

The validator falls back to `Yii::$container->get(ValueObjectMapper::class)` (0.1.0 used a private static default) and
exposes `resolvedMapper()` (`@internal` since 1.0); the model trait (through `Internal\ModelValues`) uses it, so rule and conversion share one mapper. A container singleton
overrides the codecs for all three entry points.

## Local quirks

- Infection's GitHub logger (enabled by the template `infection.json5`) fails with "cannot be made relative to the
  relative path" outside a git checkout; pass `--logger-project-root-directory=$PWD` or run after `git init`.
- Sibling packages built in parallel share PHPStan's default tmp dir (`sys_get_temp_dir()/phpstan`) and corrupted
  each other's result cache; run with `TMPDIR=$PWD/var/tmp` when that happens.
- Since 0.3.0 the default mapper is `Yii::$container->get(ValueObjectMapper::class)` when `has()` is true
  (`Internal\DefaultMapper`); a non-mapper definition is a `LogicException`, no definition falls back to one shared
  `new ValueObjectMapper()`.

## Roave BC check against v0.3.0

The first 1.0.0 run against v0.3.0 WILL be red (exit 3) with exactly one entry: `ValueObjectValidator#resolvedMapper()`
"was marked @internal" (Roave 8.27's `ExcludeInternalMethod` looks only at the from-method, and `FunctionBecameInternal`
reports the new tag). It is listed in the CHANGELOG "Upgrading from 0.x" and clears once v1.0.0 is the base tag. Not
reported: the trait helpers moved to `Internal\` (private methods are not checked), the `@internal` removed from
`dehydrate()` (the from-method was internal, so it is skipped), `DefaultMapper`'s private constructor (the from-class
was `@internal`) and the new `Internal\` classes. Keep `src/` free of `new` initializers in signatures (a changed
`new` default counts as a break). Not run locally (the VCS repository needs GitHub authentication).

## `tests/Integration` stays

It holds only `.gitkeep`, but the template `phpunit.xml.dist` (frozen) declares the `integration` suite on that
directory, and with `failOnWarning` a missing suite directory would fail the run.
