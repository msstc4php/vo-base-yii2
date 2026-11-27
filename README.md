# msstc4php/vo-base-yii2

Yii 2 glue for [`msstc4php/vo-base`][core]: a validator that asks the value object whether input is valid, action
parameters and model attributes that arrive as value objects, and an ActiveRecord behavior that keeps value objects in
your model and scalars in your database.

Requires PHP >= 8.4, `msstc4php/vo-base` `^1.4` and `yiisoft/yii2` `^2.0.55` (Composer blocks earlier 2.0.x releases
on security advisories).

## Install

Neither this package nor the core is on Packagist yet, so register both GitHub repositories:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/msstc4php/vo-base" },
        { "type": "vcs", "url": "https://github.com/msstc4php/vo-base-yii2" }
    ],
    "require": {
        "msstc4php/vo-base-yii2": "^1.0"
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
- It accepts what the behavior accepts on save, so a rule and the model agree: a composite may also come as its
  JSON text (`'{"min":1,"max":5}'`, e.g. a hidden field).
- An instance of the value object itself is valid, so rules keep working on a model whose attribute already holds
  one (see the behavior below).
- Wrong types (an array for a string, an object, a bool) are a validation error, not an exception.
- The default message is Yii's own translated `{attribute} is invalid.`; a custom message gets `{attribute}`, the
  attribute's label. Do not use `{value}` in it: Yii fills that placeholder with the submitted input, so the message
  would repeat the client's input back (to a log, an API response, another user's screen).
- `BoundedString` is refused with `InvalidConfigException`: its payload carries its own length limits, so a client
  would choose them. Validate length with Yii's `string` validator, or use `NonEmptyString`.
- Server-side only: no client-side (JavaScript) validation is generated.
- Pass `'mapper' => new ValueObjectMapper([new YourCodec()])` to validate your own composite.

## Action parameters

```php
use App\Priority;
use MSSTC4PHP\ValueObject\Identity\EmailAddress;
use MSSTC4PHP\ValueObject\Yii2\Web\ValueObjectActionParams;
use yii\web\Controller;

final class UserController extends Controller
{
    use ValueObjectActionParams;

    public function actionView(EmailAddress $email, ?Priority $priority): string
    {
        // $email is an EmailAddress; /user/view?email=nope is a 404, not a TypeError
        return $email->domain();
    }
}
```

The trait overrides `yii\web\Controller::bindActionParams()`. Without it Yii hands the raw string to the typed
parameter and PHP throws a `TypeError` (500).

- Applies to a parameter with a single named type that is a scalar-backed value object (`EmailAddress`,
  `PositiveInt`, `Uuid`, ...) or a backed enum. Inline actions and standalone `Action::run()` actions are bound alike;
  injected services still are.
- Invalid input, including an array (`?email[]=x`), is a `NotFoundHttpException` (404) whose message names the type as
  words ("email address"), never a class or the value. Its previous exception is vo-base's own, whose message may quote an excerpt
  of the input: log it, do not show it to the client.
- `?EmailAddress`: an absent, `null` or `''` parameter is `null`. A non-nullable parameter that is absent is Yii's
  400 "Missing required parameters" (listing every missing parameter), an empty one is a 404; a default value is used
  when the parameter is absent.
- An argument that already is an instance (set by your code or a filter) passes through untouched. Union and
  intersection types are left to Yii.
- A parameter typed with a class that cannot be bound, `BoundedString` (its payload carries its own bounds) or a
  composite such as `IntRange` (a URL segment cannot carry one), is a `LogicException` naming the parameter whether
  the request carries it or not: a programming error found on the first request, never the raw string reaching the
  typed parameter.
- Invalid values are always a 404, arrays and `''` on a non-nullable parameter included: as in Symfony, the route
  does not exist for that value.
- The mapper is `Yii::$container->get(ValueObjectMapper::class)`; see "Custom codecs" below to add codecs.

## Model values

```php
use MSSTC4PHP\ValueObject\Identity\EmailAddress;
use MSSTC4PHP\ValueObject\Primitive\IntRange;
use MSSTC4PHP\ValueObject\Yii2\Model\ValueObjectModel;
use MSSTC4PHP\ValueObject\Yii2\Validator\ValueObjectValidator;
use yii\base\Model;

final class SignupForm extends Model
{
    use ValueObjectModel;

    public ?string $email = null;
    public ?string $range = null;

    public function rules(): array
    {
        return [
            ['email', ValueObjectValidator::class, 'valueObjectClass' => EmailAddress::class],
            ['range', ValueObjectValidator::class, 'valueObjectClass' => IntRange::class],
        ];
    }
}

// In a REST action: an invalid model is answered with 422 and the field errors by yii\rest\Serializer.
$form = new SignupForm();
$form->load(Yii::$app->getRequest()->getBodyParams(), '');
if (!$form->validate()) {
    return $form;
}

$email = $form->valueObject('email');   // EmailAddress|null
$all = $form->valueObjects();           // ['email' => EmailAddress|null, 'range' => IntRange|null]
```

- The class (and the `mapper` option, if given) comes from the attribute's `ValueObjectValidator` rule, so the rule and
  the conversion cannot disagree; a composite may come as its payload array or as JSON text.
- A `null` or `''` attribute gives `null`; an attribute already holding the value object gives it back.
- An attribute the rule did not check, because it is empty for the rule (`skipOnEmpty`, so `[]` too) or its `when`
  condition is false, gives `null`: unvalidated input is never converted.
- `valueObject('email', EmailAddress::class)` asserts the rule's class and types the result.
- Call it after `validate()`. A model with errors, an attribute without such a rule, or a value that does not convert
  is a `LogicException`.

## Custom codecs

The validator, the model trait, the action parameters and the ActiveRecord behavior all use the mapper from the
container unless a rule or the behavior gives its own `mapper`. Define it once, with your codecs (or a package's whole
set through its `ProvidesCodecs` class), as a singleton. Without a definition they share one plain mapper; a
definition that builds anything else than a `ValueObjectMapper` throws `LogicException`:

```php
Yii::$container->setSingleton(ValueObjectMapper::class, static fn (): ValueObjectMapper => new ValueObjectMapper([new SizeCodec()]));
// vo-documents' composite names: new ValueObjectMapper(\MSSTC4PHP\ValueObject\Documents\Codecs::all())
```

## ActiveRecord behavior

```php
use MSSTC4PHP\ValueObject\I18n\CountryCode;
use MSSTC4PHP\ValueObject\Primitive\IntRange;
use MSSTC4PHP\ValueObject\Yii2\Behavior\ValueObjectAttributeBehavior;
use MSSTC4PHP\ValueObject\Yii2\Behavior\ValueObjectAttributes;

/**
 * @property EmailAddress|null $email
 * @property CountryCode|null  $country
 * @property IntRange|null     $range
 */
final class Customer extends ActiveRecord
{
    use ValueObjectAttributes; // restores the value objects when a save fails or is vetoed

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
  throws. A composite may be its payload array or JSON text.
- A `BoundedString` attribute only takes a `BoundedString` instance on save, never raw input: its payload carries
  its own length limits. If it is a *safe* attribute, `load($request->post())` can put an array there and the save
  throws `InvalidPayloadException`; keep it out of safe attributes (or `rules()`) and assign instances.
- An invalid stored value fails loudly on find with the value object's exception or `InvalidPayloadException`
  (both extend `InvalidValueObjectException`); nothing silently becomes `null`.
- A mapped class the payload mapper does not support, or an owner that is not an ActiveRecord, is an
  `InvalidConfigException` when the behavior is attached; a name that is not an attribute of the model (a typo) is
  one at the first find or save (attach stays schema-free, so a model can be built before its table exists).
- Any `BaseActiveRecord` works. Only a `yii\db\ActiveRecord` is asked whether a column is a JSON column; other
  ActiveRecords (MongoDB, Redis, Elasticsearch) get composites as JSON strings.
- `dehydrate()` (value objects to payloads) and `hydrate()` (the value objects back) are public: the behavior calls
  them around every save, and you call them around a write that skips the save events (see below). The find handler,
  `hydrateFromStorage()`, is internal.
- Without a `mapper` option the container's mapper is used (see "Custom codecs"); pass
  `'mapper' => new ValueObjectMapper([new YourCodec()])` to give one behavior a mapper of its own.

### Failed and vetoed saves

`afterSave` never runs when an INSERT/UPDATE throws (an `IntegrityException` on a unique index, say) or a
`beforeSave` handler sets `isValid = false`. Without help the model would keep the payloads. The
`ValueObjectAttributes` trait overrides `insert()`/`update()` and puts the value objects back in a `finally`; without
the trait, call `$model->getBehavior('valueObjects')->hydrate()` after a failed save. `hydrate()` is idempotent and
only restores the instances the behavior itself replaced, so rejected raw input is never turned into a value object.

`updateAttributes()` skips the save events entirely; the trait overrides it to write payloads and restore the value
objects. Without the trait, use `update(false, ['email'])` instead, or call the behavior's `dehydrate()` before the
write and `hydrate()` after it, in a `finally`.

### Event order

Behaviors attach before any `on()` handler, so the behavior's handlers run first:

- a `beforeInsert`/`beforeUpdate` handler (and code in an overridden `beforeSave()` after `parent::beforeSave()`)
  sees payloads;
- an `afterInsert`/`afterUpdate` handler sees value objects, but `AfterSaveEvent::$changedAttributes` holds the
  **old payloads** (scalars/JSON), not value objects.

## Stored formats

The same formats as every other `vo-base` bridge, so a row written by one reads through another.

| Value object | Column | Example |
|---|---|---|
| string-backed (`EmailAddress`, `Uuid`, `HexColor`, ...) | string | `ann@example.com` |
| int-backed (`PositiveInt`, `Port`, ...) | integer | `42` |
| float-backed (`Percentage`, ...) | float | `12.5` |
| backed enum (`CountryCode`, `CurrencyCode`, your own) | the enum value | `DE` |
| composite (`IntRange`, `GeoCoordinates`, `DateRange`, `BoundedString`) | JSON text, or an array for a column Yii maps to an array (JSON on MySQL/PostgreSQL) | `{"min":1,"max":5}` |

## Backward compatibility

1.x follows semantic versioning; a breaking change needs 2.0. Covered by 1.x:

- **Code API:** these classes and traits, with the public properties and methods below. None declares a constructor
  of its own: the validator and the behavior are Yii objects, configured through Yii's config arrays.
  - `Validator\ValueObjectValidator`, used as `['attr', ValueObjectValidator::class, 'valueObjectClass' => X::class]`,
    with the options `valueObjectClass` and `mapper` next to Yii's own (`message`, `skipOnEmpty`, `when`, ...);
    `init()` and `validateValue()` are Yii's to call;
  - `Behavior\ValueObjectAttributeBehavior`, with the options `attributes` (attribute name => class) and `mapper`, and
    the public pair `dehydrate()` and `hydrate()`; `events()` and `attach()` are Yii's to call;
  - `Behavior\ValueObjectAttributes`, the ActiveRecord trait, overriding `insert()`, `update()` and
    `updateAttributes()` with Yii's parameter lists, returning `bool`, `false|int` and `int` (an override in your
    model must declare a compatible return type);
  - `Model\ValueObjectModel`, the model trait, with `valueObject(string $attribute, ?string $class = null)` and
    `valueObjects()`, which read the attribute as Yii's validators do (`$model->attribute`, so a getter wins over a
    non-public property of the same name);
  - `Web\ValueObjectActionParams`, the controller trait, overriding `bindActionParams($action, $params)`.

  The traits hold only these methods. No 1.x release adds a method, property or constant to them: a method your
  class declares would silently replace the trait's (or fail to compile if the signatures differ), a trait method
  would silently replace one your class inherits, and a property or constant of the same name is a conflict.
- **Names users write:** the option names above; the container id `ValueObjectMapper::class` in `Yii::$container`,
  which the validator, the behavior, the model trait and the action parameters use when no `mapper` option is given
  (one shared plain mapper without a definition); the action parameter binding: a parameter with a single named type
  that is a scalar-backed value object or a backed enum, by parameter name, in inline and standalone actions, leaving
  instances, union and intersection types and unsupported classes alone.
- **Messages:** the validator's default, Yii's own translation key `{attribute} is invalid.` (category `yii`) with its
  `{attribute}` placeholder, and Yii's `Missing required parameters: {params}` for the 400. Changing either default
  needs 2.0.
- **Data:** the stored formats above. They are vo-base's payload layer, the same as the Doctrine types and the other
  bridges write, so a row written through one reads through another. Request input and action parameters are read in
  the same forms.
- **Exceptions:** configuration errors of the validator and the behavior are Yii's `InvalidConfigException`, those of
  the traits `LogicException`; that split is kept.
  - `yii\base\InvalidConfigException`: from the validator's `init()` for a missing `valueObjectClass`, a class the
    mapper does not support or a trusted-source-only class (`BoundedString`); from the behavior's `attach()` for an
    owner that is not a `BaseActiveRecord` or a mapped class the mapper does not support, and at the first find or
    save for a mapped name that is not an attribute of the model;
  - the behavior: vo-base's `InvalidValueObjectException` or a subclass, unchanged, for an invalid stored or raw value;
    `InvalidPayloadException` with no previous exception for a raw value of a trusted-source-only class and for
    composite JSON text that is not an object; `InvalidPayloadException` with the `JsonException` as previous for
    composite JSON text that does not parse or a composite that cannot be encoded. The `ValueObjectAttributes` trait
    rethrows whatever the save throws, after the value objects are back;
  - the validator throws nothing for invalid input: that is a validation error;
  - `yii\web\NotFoundHttpException` (404) for an invalid action parameter, with vo-base's own exception as previous,
    passed through unchanged, and with no previous for `''` or `null` on a non-nullable parameter;
    `yii\web\BadRequestHttpException` (400) for missing required parameters, with no previous;
  - `LogicException` from the action parameters for a parameter typed with a composite or a trusted-source-only
    class; from the model trait for a model with errors, an attribute without the rule, a class other than the
    rule's, or a rule without `valueObjectClass` (no previous), and for a value that does not convert (an
    `InvalidValueObjectException` as previous: vo-base's own, or for composite JSON text the bridge's
    `InvalidPayloadException`);
  - `LogicException` from any component without a `mapper` option when the container's `ValueObjectMapper`
    definition builds something else.
- **Enums:** the validator, the behavior, the model trait and the action parameters handle backed enums; an unknown
  case in an action parameter is a 404, like any invalid value.
- **Versions:** a minor may add support for a new Yii or PHP major; dropping a supported Yii or PHP version needs a
  major, and so does raising the floor of any third-party dependency (Yii, PHP). A minor may raise the floor of a
  `msstc4php/*` package within its major (those follow the same promise).

A minor never refuses a value an earlier 1.x accepted: the validator, the behavior, the model trait and the action
parameters add no validation of their own, the value objects (vo-base and the domain packages) decide, and they follow
the same rule.

Not covered: exception message wording (the package's messages name classes, attributes, parameters or type
labels, never the value), except the translation keys above; anything marked `@internal` (`Internal\`,
`ValueObjectValidator::resolvedMapper()` and `ValueObjectAttributeBehavior::hydrateFromStorage()`).

## Development

```
COMPOSER=composer-local.json composer update --ignore-platform-req=php   # against ../vo-base
make check   # php -l, PHPStan level max, cs-fixer, composer validate/audit, Rector, deptrac
make test    # PHPUnit
make fix     # cs-fixer + Rector, applied
```

[core]: https://github.com/msstc4php/vo-base
