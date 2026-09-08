# Articulate ORM Demo

This repository is the runnable demo and documentation companion for [Articulate](https://github.com/articulate-orm/core), a lightweight PHP ORM built around PHP 8 attributes, an identity map, Unit of Work change tracking, relation mapping, migrations, and query-building utilities.

Use this project when you want to see Articulate features in context. Each guide explains the concept, points to runnable console commands, and links to the previous and next topic so the repository can be read as a tutorial.

## What This Repository Contains

| Area | Purpose                                                                                                   |
|------|-----------------------------------------------------------------------------------------------------------|
| [`documentation/`](documentation/README.md) | Concept guides ordered from basic setup to advanced runtime behavior.                                     |
| [`src/Features/`](src/Features) | Demo feature modules for catalog, customers, orders, tagging, analytics, and imports.                     |
| [`migrations/`](migrations) | MySQL/PostgreSQL schema migrations used by the examples. For demo-purposes both versions being generated. |
| [`GLOSSARY.md`](GLOSSARY.md) | Short definitions with links to the guide where each term is explained.                                   |
| [`documentation/known-limitations/`](documentation/known-limitations/README.md) | Structured list of current library gaps and demo workarounds.                                             |

## Learning Path

Start at the top and move down. The order intentionally goes from the smallest working setup to cross-cutting and performance-oriented features.

| Step | Guide | What You Learn | Runnable Feature Command |
|------|-------|----------------|------------------|
| 1 | [Getting Started](documentation/getting-started/README.md) | Install Articulate, configure database access, and wire `Connection` plus `EntityManager`. | `app:catalog:crud` |
| 2 | [Entity Mapping](documentation/entity-mapping/README.md) | Map PHP classes, properties, primary keys, standard/concurrent indexes, and same-table projections. | `app:catalog:crud`, `app:customers:cross-entity` |
| 3 | [Migrations](documentation/migrations/README.md) | Generate and apply schema changes from entity metadata. | `articulate:init`, `articulate:migrate`, `articulate:diff` |
| 4 | [Relationships](documentation/relationships/README.md) | Model one-to-one, one-to-many, many-to-one, many-to-many, and polymorphic relations. | `app:catalog:crud`, `app:orders:query`, `app:tagging:demo` |
| 5 | [Query Builder](documentation/query-builder/README.md) | Build filters, joins, aggregates, subqueries, and reusable Criteria. | `app:catalog:query`, `app:orders:query`, `app:analytics:report` |
| 6 | [Pagination and Filtering](documentation/pagination-filtering/README.md) | Use offset pagination, cursor pagination, ordering, and soft-delete filters. | `app:customers:browse`, `app:customers:soft-delete` |
| 7 | [Lifecycle Callbacks](documentation/lifecycle-callbacks/README.md) | Run entity hooks around persistence, updates, removal, and hydration. | `app:customers:lifecycle` |
| 8 | [Custom Types](documentation/custom-types/README.md) | Convert between PHP value objects/enums and database values. | `app:catalog:crud` |
| 9 | [Transactions and Locking](documentation/transactions-locking/README.md) | Use transactional writes, manual transaction control, and `SELECT ... FOR UPDATE`. | `app:orders:place`, `app:orders:deadlock` |
| 10 | [Performance](documentation/performance/README.md) | Understand identity map behavior, result cache, second-level cache, query logging, partial hydration, and batch iteration. | `app:analytics:report`, `app:analytics:batch`, `app:import:run` |

## Demo Feature Modules

The source code is organized by realistic e-commerce features. These modules are examples first: they are intentionally small, focused, and built to expose ORM behavior.

| Feature | Source | Commands | Demonstrates |
|---------|--------|----------|--------------|
| Catalog | [`src/Features/Catalog/`](src/Features/Catalog/README.md) | `app:catalog:crud`, `app:catalog:query` | Entity mapping, standard/concurrent indexes, enum conversion, many-to-many products/categories, basic queries. |
| Customer Accounts | [`src/Features/CustomerAccounts/`](src/Features/CustomerAccounts/README.md) | `app:customers:lifecycle`, `app:customers:browse`, `app:customers:soft-delete`, `app:customers:cross-entity` | Lifecycle callbacks, custom repositories, soft delete, cursor pagination, same-table projections, L2 sibling eviction. |
| Orders | [`src/Features/Orders/`](src/Features/Orders/README.md) | `app:orders:place`, `app:orders:query`, `app:orders:deadlock` | Transactions, pessimistic locks, UUID IDs, order/item relations, complex query builder usage. |
| Tagging | [`src/Features/Tagging/`](src/Features/Tagging/README.md) | `app:tagging:demo` | Polymorphic many-to-many tagging, morph aliases, pivot-table queries. |
| Analytics | [`src/Features/Analytics/`](src/Features/Analytics/README.md) | `app:analytics:report`, `app:analytics:batch` | Read-only projections, aggregates, result cache, query logging, batch reads. |
| Bulk Import | [`src/Features/BulkImport/`](src/Features/BulkImport/README.md) | `app:import:run` | Write-side projections, scoped units of work, bounded-memory imports. |

## Quick Start

```bash
docker compose up -d
docker compose exec php composer install
docker compose exec php bin/console articulate:init
docker compose exec php bin/console articulate:migrate
docker compose exec php bin/console app:catalog:crud
```

The demo ships with migrations, so `articulate:migrate` is enough from a clean checkout. Run `articulate:diff` when you change mapped entities and want to generate new migration files.

To inspect available demo commands:

```bash
docker compose exec php bin/console list app
```

## Configuration Reference

| Variable | Example | Description |
|----------|---------|-------------|
| `DATABASE_DSN` | `mysql:host=mysql;dbname=articulate_test;charset=utf8mb4` | PDO DSN. |
| `DATABASE_USER` | `user` | Database username. |
| `DATABASE_PASSWORD` | `userpassword` | Database password. |
| `DATABASE_NAME` | `articulate_test` | Database name referenced by the DSN. |
| `ARTICULATE_MIGRATIONS_PATH` | `migrations/mysql` | Directory where migration files are read and written. |
| `APP_ENV` | `dev` / `test` | Symfony environment; tests use `.env.test` overrides. |

Migration-related Symfony parameters live in `config/services.yaml`:

| Parameter | Purpose |
|-----------|---------|
| `articulate_entities_path` | Directory scanned for `#[Entity]` classes. |
| `articulate_migrations_path` | Directory used by migration commands. |
| `articulate_migrations_namespace` | Namespace for generated migration classes. |

## Requirements

- PHP 8.4+
- Composer
- Docker and Docker Compose for the demo database/services
- MySQL 8.0+ or PostgreSQL 15+

## Local Development

```bash
docker compose up -d
docker compose exec php composer install
docker compose exec php bin/console articulate:migrate
docker compose exec php vendor/bin/phpunit
```

Run one example command:

```bash
docker compose exec php bin/console app:orders:place
```

Run architecture validation:

```bash
docker compose exec php bin/console articulate:validate
```

## Notes and Known Gaps

Reader-facing caveats live in [Known Limitations](documentation/known-limitations/README.md). Maintainer implementation notes and recheck lists stay in [`FEATURES_PLAN.md`](FEATURES_PLAN.md).
