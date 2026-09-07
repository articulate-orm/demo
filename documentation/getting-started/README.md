# Getting Started

Install Articulate, configure database access, and wire the two services used by the rest of the examples.

**Runnable feature command:** `app:catalog:crud`

## What This Covers

- Installing the package with Composer
- Defining database connection settings
- Registering `Connection` and `EntityManager`
- Running the first demo command

## Installation

Add the package via Composer:

```bash
composer require articulate-orm/core
```

The demo repository already has this dependency in `composer.json`.

## Configuration

Configure database access through environment variables:

```env
DATABASE_DSN=mysql:host=mysql;dbname=articulate_test;charset=utf8mb4
DATABASE_USER=user
DATABASE_PASSWORD=userpassword
```

The demo also uses `ARTICULATE_MIGRATIONS_PATH` so migration commands know whether to read/write MySQL or PostgreSQL migrations.

## Services

Register the core Articulate services in the container:

- `Articulate\Connection` receives the DSN, username, and password.
- `Articulate\Modules\EntityManager\EntityManager` receives the `Connection`.

Most examples use `EntityManager` directly to persist, find, query, remove, and flush entities.

## First Run

```bash
docker compose up -d
docker compose exec php bin/console articulate:init
docker compose exec php bin/console articulate:migrate
docker compose exec php bin/console app:catalog:crud
```

## Navigation

Previous: [Repository README](../../README.md)  
Base: [Documentation Index](../README.md)  
Next: [Entity Mapping](../entity-mapping/README.md)
