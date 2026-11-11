# msstc4php/vo-base-yii2

Yii 2 glue for [`msstc4php/vo-base`][core]: a validator that asks the value object whether input is valid, and an
ActiveRecord behavior that keeps value objects in your model and scalars in your database.

Requires PHP >= 8.4 and `yiisoft/yii2` `^2.0.55` (Composer blocks earlier 2.0.x releases on security advisories).

## Install

Neither this package nor the core is on Packagist yet, so register both GitHub repositories:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/msstc4php/vo-base" },
        { "type": "vcs", "url": "https://github.com/msstc4php/vo-base-yii2" }
    ],
    "require": {
        "msstc4php/vo-base-yii2": "^0.1"
    }
}
```

`yiisoft/yii2` needs its `bower-asset/*` packages; a Yii 2 application already has them (asset-packagist or the
asset plugin). This package does not change how they are resolved.

## Validator

```php
use MSSTC4PHP\ValueObject\Identity\EmailAddress;
use MSSTC4PHP\ValueObject\Primitive\PositiveInt;
use MSSTC4PHP\ValueObject\Yii2\Validator\ValueObjectValidator;

public function rules(): array
{
    return [
        [['email', 'age'], 'required'],
        ['email', ValueObjectValidator::class, 'valueObjectClass' => EmailAddress::class],
        ['age', ValueObjectValidator::class, 'valueObjectClass' => PositiveInt::class, 'message' => '{attribute} must be positive.'],
    ];
}
```

- The option is `valueObjectClass`, not `class`: Yii uses a rule's `class` key for the validator itself.
- `null` and `''` pass; making a field mandatory is `required`'s job (empty values are skipped by default anyway).
- Form input arrives as strings: an int-backed value object (and an int-backed enum) accepts a canonical decimal-digit string (`"080"` is rejected),
  a float-backed one a numeric string. A composite (`IntRange`, `GeoCoordinates`, `DateRange`) takes its payload
  array, e.g. `['min' => 1, 'max' => 5]` from a JSON body.
- An instance of the value object itself is valid, so rules keep working on a model whose attribute already holds
  one (see the behavior below).
- Wrong types (an array for a string, an object, a bool) are a validation error, not an exception.
- The default message is Yii's own translated `{attribute} is invalid.`; `{attribute}` and `{value}` are available.
- `BoundedString` is refused with `InvalidConfigException`: its payload carries its own length limits, so a client
  would choose them. Validate length with Yii's `string` validator, or use `NonEmptyString`.
- Server-side only: no client-side (JavaScript) validation is generated.
- Pass `'mapper' => new ValueObjectMapper([new YourCodec()])` to validate your own composite.

## ActiveRecord behavior

```php
use MSSTC4PHP\ValueObject\I18n\CountryCode;
use MSSTC4PHP\ValueObject\Primitive\IntRange;
use MSSTC4PHP\ValueObject\Yii2\Behavior\ValueObjectAttributeBehavior;

/**
 * @property EmailAddress|null $email
 * @property CountryCode|null  $country
 * @property IntRange|null     $range
 */
final class Customer extends ActiveRecord
{
    public function behaviors(): array
    {
        return [
            'valueObjects' => [
                'class' => ValueObjectAttributeBehavior::class,
                'attributes' => [
                    'email' => EmailAddress::class,
                    'country' => CountryCode::class,
                    'range' => IntRange::class,
                ],
            ],
        ];
    }
}

$customer = Customer::findOne(1);
$customer->email;                       // EmailAddress
$customer->email = EmailAddress::from('new@example.com');
$customer->save();                      // UPDATE ... SET email = 'new@example.com'
$customer->email;                       // EmailAddress again
```

- After find and refresh, column values become value objects; `null` stays `null`.
- Before insert and update the value objects become scalars (JSON for composites), afterwards value objects again.
- An unchanged attribute causes no UPDATE; an equal value object assigned anew causes none either (the payloads are
  compared). A changed one is written.
- A raw value in the attribute (a string from `load()`) is validated and canonicalised on save; an invalid one
  throws. A `BoundedString` attribute only takes a `BoundedString` instance on save, never raw input.
- An invalid stored value fails loudly on find with the value object's exception or `InvalidPayloadException`
  (both extend `InvalidValueObjectException`); nothing silently becomes `null`.
- A mapped class the payload mapper does not support, or an owner that is not an ActiveRecord, is an
  `InvalidConfigException` when the behavior is attached.
- Pass `'mapper' => new ValueObjectMapper([new YourCodec()])` to map your own composite.

## Stored formats

The same formats as every other `vo-base` bridge, so a row written by one reads through another.

| Value object | Column | Example |
|---|---|---|
| string-backed (`EmailAddress`, `Uuid`, `HexColor`, ...) | string | `ann@example.com` |
| int-backed (`PositiveInt`, `Port`, ...) | integer | `42` |
| float-backed (`Percentage`, ...) | float | `12.5` |
| backed enum (`CountryCode`, `CurrencyCode`, your own) | the enum value | `DE` |
| composite (`IntRange`, `GeoCoordinates`, `DateRange`, `BoundedString`) | JSON text, or an array for a column Yii maps to an array (JSON on MySQL/PostgreSQL) | `{"min":1,"max":5}` |

## Development

```
COMPOSER=composer-local.json composer update --ignore-platform-req=php   # against ../vo-base
make check   # php -l, PHPStan level max, cs-fixer, composer validate/audit, Rector, deptrac
make test    # PHPUnit
make fix     # cs-fixer + Rector, applied
```

[core]: https://github.com/msstc4php/vo-base
