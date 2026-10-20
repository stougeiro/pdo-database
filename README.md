![PHP](https://img.shields.io/badge/PHP-%20^8.2-777BB4)
![PHPStan-Level](https://img.shields.io/badge/PHPStan-Level%209-224488)
![Pest-php](https://img.shields.io/badge/Tests-Passed%20with%20Skips-d4a017)
![License](https://img.shields.io/badge/License-MIT-777)

# Database

A dialect-agnostic PDO model for SQLite, MySQL, MariaDB and PostgreSQL.

Write one model, run it against four engines. SQL is not one thing: SQLite,
MySQL, MariaDB and PostgreSQL disagree on quoting, pagination, `LIKE`/`ILIKE`, `NULL`
ordering and generated keys. A neutral intermediate representation normalizes
what is common — operators, values, ordering, pagination, aggregation — and the
driver decides *how* its dialect does it. The divergence stays where it belongs,
in the driver, and the model never branches on engine.

The pipeline is four stages:

```
Config
  │ dsn + keys
  ▼
Connection
  │ PDO + transactions + raw SQL + bind
  ▼
Driver
  │ IR → SQL (translator, delegates execution)
  ▼
Model
  │ arrays → IR → Result
```

Responsibilities are strictly layered: the **Connection** is the only stage that
touches the native handle — it owns the physical link, transactions, raw SQL
(`fetch`/`fetchAll`/`execute`) and the binding of every value. The **Driver** is a
pure IR translator: it renders the neutral statement into SQL through its dialect
and delegates execution to the Connection, returning an engine-neutral raw result.
The **Model** is a spearhead that turns arrays into a `ResultInterface` — it
exposes neither driver nor connection. Everything is reached through a static
registry.

## ✨ Features

- **Model in front, driver as translator** — a model is a subclass declaring its
  table (and, optionally, its connection name and generated-key column). CRUD
  is driven by arrays and always returns a `ResultInterface`; the single-method
  driver (`translate(StatementInterface): RawInterface`) is the only collaborator.
- **Static registry** — one facade ties a connection name to a driver nickname,
  builds the matching config and caches a shared `Connection` per name. Nothing
  connects until the first query; the same instance is what lets a transaction
  span several models.
- **Executable IR** — a `Statement` envelope (`kind`, `table`, `query`, `data`,
  `returning`, `key`) is validated at construction: an insert cannot carry a
  query, a write needs typed data, an update/delete needs a non-empty `WHERE`,
  and `returning` is only meaningful on an insert. A malformed statement never
  reaches the engine.
- **Raw on the surface, `Param` underneath** — the model and the DSL accept raw
  PHP values (strings, ints, floats, `DateTimeInterface`, …) so the call site
  stays fluid; each one is wrapped into a typed `Param` before it leaves the
  model. A hand-built `Statement` is stricter and holds `Param`s outright.
- **Declarative array DSL** — a static builder lowers filters (comparison,
  `in`/`not_in`, `between`, `like`/`ilike`, `is_null`/`is_not_null`, `AND`/`OR`/
  `NOT` and nested groups), ordering, pagination, columns, `groupBy`, `having`
  and aggregates into the neutral query tree, failing fast before execution.
- **Two pagination strategies** — positional (`limit`/`offset`) and keyset
  (cursor); a cursor never combines with an offset. Cursors over a keyset use
  `rowid` on SQLite and require an explicit `ORDER BY` where the engine needs it.
- **Generated keys, absorbed** — an insert hands back the stored record where the
  engine supports `RETURNING` (PostgreSQL; SQLite ≥ 3.35 and MariaDB ≥ 10.5), so
  database-filled columns (defaults, timestamps, generated values) come back with
  it. Where it does not (MySQL), the driver falls back to the native
  last-insert-id and, when `emulateReturning` is on (the default), reads the
  record back with one follow-up select — the caller always sees the same shape.
  Turn emulation off when only the generated key matters. The three paths resolve
  in strict priority:

  | `returning` | `supportsReturning` | `emulateReturning` | result |
  |---|---|---|---|
  | `[]` | — | — | last-insert-id (no record) |
  | non-empty | `true` | ignored | **native `RETURNING`** |
  | non-empty | `false` | `true` | **native-like** (last-id + follow-up select) |
  | non-empty | `false` | `false` | last-insert-id only |

  `supportsReturning` is the higher-priority switch: it is the AND of the
  dialect (what the engine *can* do) and the config (whether this server's
  *version* qualifies). Default `true`; declare `false` for a server older than
  the clause.
- **Raw SQL on the Connection** — callers who own their SQL (joins, `WITH`,
  window functions — anything the neutral IR does not model) use
  `fetch`/`fetchAll`/`execute` on the shared connection, each binding typed
  `Param`s. The IR translator stays IR-only.
- **Real per-engine divergences** — identifier quoting and escaping, `ILIKE`
  (native on PostgreSQL, `LOWER() LIKE LOWER(?)` elsewhere), `LIMIT` for an
  offset-only page, `NULL` ordering (native or emulated with `col IS NULL`), and
  the generated-key strategy. A clause an engine cannot express throws — it is
  never mistaken for an empty result.
- **Transactions on the connection** — `begin()`/`commit()`/`rollback()`/
  `inTransaction()` live on the connection, not the model, so a transaction
  spans every model bound to the same name.
- **Security by construction** — a value never becomes SQL text. Every value is
  bound as a typed `Param` (`ParamType` → `PDO::PARAM_*`) through a prepared
  statement, operators and directions are enums, `LIMIT`/`OFFSET` are validated
  integers, and identifiers are the dialect's responsibility, which **must
  escape** the quote delimiter. Raw values at the call site are not a hole:
  they are converted to `Param`s inside the model, before the compiler runs.
- **Non-negotiable connection invariants** — every fresh handle is put into
  exception error mode with associative fetching; these cannot be turned off by
  config.
- **Per-dialect config, no silent defaults** — each engine has its own value
  object that validates its required keys on construction, so the driver never
  receives a half-specified connection.

---

## 📦 Installation

Install via Composer:

```bash
composer require stougeiro/pdo-database
```

Requires PHP `^8.2` and the PDO driver for the engine you use
(`pdo_sqlite`, `pdo_mysql` and/or `pdo_pgsql`). MariaDB uses `pdo_mysql`.

## 🚀 Usage

### Configure a connection

```php
use STDW\Pdo\ConnectionRegistry;

ConnectionRegistry::configure([
    'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
]);
```

Switching engines is a change of nickname and keys — nothing else in your code
moves:

```php
ConnectionRegistry::configure([
    'default' => [
        'driver'   => 'mysql',
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'schema'   => 'app',
        'charset'  => 'utf8mb4',
        'username' => 'root',
        'password' => 'secret',
    ],
    'analytics' => [
        'driver'   => 'postgres',
        'host'     => 'db',
        'port'     => 5432,
        'schema'   => 'app',
        'username' => 'postgres',
        'password' => 'secret',
    ],
]);
```

And `mariadb` (same keys as `mysql` — it speaks the MySQL wire protocol — but
with a native `RETURNING` on MariaDB ≥ 10.5):

```php
ConnectionRegistry::configure([
    'default' => [
        'driver'   => 'mariadb',
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'schema'   => 'app',
        'charset'  => 'utf8mb4',
        'username' => 'root',
        'password' => 'secret',
    ],
]);
```

Every connection also accepts two optional generated-key flags. `supportsReturning`
(default `true`) is the higher-priority switch, ANDed with the dialect's own:
set it to `false` when the server is older than the `RETURNING` clause (MariaDB
< 10.5, SQLite < 3.35) to use the last-insert-id path instead. `emulateReturning`
(default `true`) is only consulted when the native clause is off: when on,
`create()` returns the whole stored record via one follow-up select; turn it off
to skip that select when only the generated key is needed. Native `RETURNING`
always wins, so `emulateReturning` is irrelevant on PostgreSQL, modern MariaDB
and modern SQLite.

### Define a model

```php
use STDW\Pdo\Model\ModelAbstracted;

final class User extends ModelAbstracted
{
    protected string $table = 'users';
    // protected string $connection = 'default'; // optional
    // protected string $key = 'id';              // generated-key column
}
```

### CRUD

```php
$user = new User();

// create: the stored record is returned (with database-filled columns)
$created = $user->create(['name' => 'Ada', 'age' => 36]);
// ['name' => 'Ada', 'age' => 36, 'id' => 1]

// read: one record by filter (count 1 or 0)
$row = $user->read(['name' => 'Ada'])->data();

// list: filter, order and pagination
$users = $user->list(
    filter: ['age gte' => 18],
    order: ['age' => 'desc'],
    pagination: ['limit' => 20],
)->data();

// update / delete always require a filter; count is the affected rows
$user->update(['active' => 0], ['age lt' => 18]);
$user->delete(['active' => 0]);
```

### Filters

```php
$user->list(['age between' => [18, 65]]);
$user->list(['role in' => ['admin', 'editor']]);
$user->list(['name ilike' => 'a%']);          // native or emulated per engine
$user->list(['email is_null' => null]);
$user->list([
    ['active' => 1],
    'AND',
    [['role in' => ['admin', 'editor']], 'OR', ['name ilike' => 'a%']],
]);
```

### Pagination

```php
$user->list(pagination: ['limit' => 10, 'offset' => 50]);        // positional
$user->list(order: ['id' => 'asc'], pagination: ['cursor' => 42, 'limit' => 10]); // keyset
```

### Transactions spanning models

```php
$connection = ConnectionRegistry::get(); // the shared connection for 'default'

$connection->begin();

(new User())->create(['name' => 'Ada']);
(new Order())->create(['user_id' => 1, 'total' => 99]);

$connection->commit(); // or $connection->rollback();
```

### Raw IR access

```php
use STDW\Contract\Pdo\Statement\Type\StatementKind;
use STDW\Pdo\QueryTree\QueryTreeBuilder;
use STDW\Pdo\Statement\Statement;

$raw = ConnectionRegistry::resolve()->translate(new Statement(
    StatementKind::Select,
    'users',
    QueryTreeBuilder::query(
        aggregates: [['alias' => 'total', 'function' => 'count', 'column' => '*']],
        filter: ['active' => 1],
    ),
));

$total = $raw->rows()[0]['total'];
```

### Raw SQL access

When the IR does not model what you need, drop to the connection and bind your
own values:

```php
$connection = ConnectionRegistry::get();

$rows = $connection->fetchAll(
    'SELECT u.id, COUNT(o.id) AS orders FROM users u '
    . 'LEFT JOIN orders o ON o.user_id = u.id WHERE u.active = ? GROUP BY u.id',
    [Param::int(1)],
);
```

---

## 🔒 Security

SQL injection needs a value to reach the SQL text. Here it never does: a value
only ever exists as a bind slot.

There are two layers, and it helps to hold them apart.

**On the surface — raw values are welcome.** The model and the DSL take plain
PHP values, so the call site stays ergonomic:

```php
$user->read(['name' => $name]);

$user->create(['name' => $name, 'age' => $age]);
```

That is not a hole. `name` and `age` are wrapped into typed `Param`s internally
(`ModelAbstracted::params`, `FilterBuilder::toParam`) *before* the compiler runs,
and a `Param` is never string-concatenated into the statement.

**At the boundary — everything is a `Param`.** The compiler emits a `?` for
every value and the Connection binds it positionally through
`bindValue($param->value(), $param->type()->value)` on a prepared statement. The
rules that hold:

- **Values**: always bound as typed `Param`s — interpolation never happens.
- **Identifiers** (table, column, alias): quoted by the dialect, which escapes
  the quote delimiter; this is the only identifier boundary.
- **`LIMIT`/`OFFSET`**: the only values rendered as literals, and they are
  validated non-negative integers.
- **A hand-built `Statement`** is stricter still — it holds `Param`s outright
  and rejects raw data at construction.

A raw malicious value is just a string that gets bound:

```php
// The value is stored and matched verbatim; the table is untouched.
$user->create(['name' => "Robert'); DROP TABLE users; --"]);

$user->read(['name' => "Robert'); DROP TABLE users; --"])->data();
// → ['id' => N, 'name' => "Robert'); DROP TABLE users; --"]
```

### Typing on purpose

Raw values are safe, but when the *type* matters, say it — this is about
precision, not protection:

- `Param::null()` — a real SQL `NULL`, distinct from an empty string.
- `Param::bool(true)` — bound as a boolean where the engine distinguishes it.
- `Param::binary($blob)` — bound as a LOB.
- `Param::int(5)` — pinned as an integer.

Everything else — strings, floats, `DateTimeInterface`, arrays, `Stringable` —
is folded to the right `Param` by `Param::from()` when you pass it raw.

---

## 🧪 Testing

```bash
composer pest     # run the suite
composer stan     # PHPStan level 9
composer cover    # coverage report (100% of lines)
composer bench    # informative micro-benchmarks
```

The suite is hermetic: it runs against SQLite `:memory:` by default. Tests that
need a live MySQL, MariaDB or PostgreSQL are **opt-in** — they skip unless the
matching environment is provided, so a normal run needs no service (MariaDB
reuses the MySQL variables, see its integration test):

```bash
PDO_MYSQL_TEST_HOST=127.0.0.1 PDO_MYSQL_TEST_PORT=3306 \
PDO_MYSQL_TEST_DATABASE=test PDO_MYSQL_TEST_USERNAME=root \
PDO_MYSQL_TEST_PASSWORD=secret \
composer pest

PDO_PGSQL_TEST_HOST=127.0.0.1 PDO_PGSQL_TEST_PORT=5432 \
PDO_PGSQL_TEST_DATABASE=test PDO_PGSQL_TEST_USERNAME=postgres \
PDO_PGSQL_TEST_PASSWORD=secret \
composer pest
```

That is why the test badge reads *Passed with Skips*: the integration tests are
intentionally skipped when their engine is not available.

The security claim in the section above is not taken on faith — it is tested.
A CRUD suite drives every write/read path with injection payloads and proves the
table survives and the value is stored verbatim, and a compiler-invariant suite
asserts that no value ever appears in the generated SQL (only `?`), across all
dialects and every operator.

---

## 🧠 Why?

SQL is not one thing. SQLite, MySQL, MariaDB and PostgreSQL disagree on quoting,
pagination, `LIKE`/`ILIKE`, `NULL` ordering and generated keys. A model written
directly against a dialect is trapped in it.

This package splits the problem: neutral types describe *what* to do, and each
driver dialect decides *how* its engine does it. One model, four dialects —
the divergence stays where it belongs, in the driver, and the model stays
dialect-agnostic.

---

## 🤝 Contributions

Contributions are welcome.
Feel free to open issues or submit pull requests.

<br>

[<img src="https://cdn.buymeacoffee.com/buttons/v2/default-yellow.png" width="170"/>](https://www.buymeacoffee.com/stougeiro)
