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

## A failed save leaves payloads in the model

`beforeSave` dehydrates; `afterSave` re-hydrates. If the INSERT/UPDATE throws, or a later `beforeSave` handler sets
`isValid = false`, `afterSave` never runs and the attributes keep their scalar/JSON payloads. Reload or discard the
model after a failed save.

## `getDirtyAttributes()` compares instances outside `save()`

Outside `save()` an equal value object assigned anew shows as dirty (Yii compares with `!==`); `save()` itself
compares payloads and writes nothing. `isAttributeChanged()` has the same instance semantics.

## Local quirks

- Infection's GitHub logger (enabled by the template `infection.json5`) fails with "cannot be made relative to the
  relative path" outside a git checkout; pass `--logger-project-root-directory=$PWD` or run after `git init`.
- Sibling packages built in parallel share PHPStan's default tmp dir (`sys_get_temp_dir()/phpstan`) and corrupted
  each other's result cache; run with `TMPDIR=$PWD/var/tmp` when that happens.
