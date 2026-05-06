# Helix Laravel Backend

This is a full Laravel application for the Helix AI chatbot builder, configured for PostgreSQL migrations.

The app includes Laravel framework files, Composer dependencies, Eloquent models, seeders, and database migrations for:

- dashboard users with UUID primary keys
- chatbots with API keys, settings, and widget appearance
- knowledge sources from files, URLs, and pasted text
- document chunks with PostgreSQL full-text search and `pgvector` embeddings
- conversations and messages
- analytics events
- leads, feedback, notifications, integrations
- Laravel cache, jobs, and sessions tables

## Requirements

- PHP 8.3+
- Composer
- PostgreSQL
- PostgreSQL extensions: `pgcrypto` and `vector` from pgvector
- PHP extension: `pdo_pgsql`

## PostgreSQL Setup

Create the database:

```bash
createdb helix
```

Enable extensions as a PostgreSQL superuser or database owner:

```bash
psql -d helix -c "CREATE EXTENSION IF NOT EXISTS pgcrypto;"
psql -d helix -c "CREATE EXTENSION IF NOT EXISTS vector;"
```

Update `.env` if your local credentials are different:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=helix
DB_USERNAME=postgres
DB_PASSWORD=
```

## Install And Migrate

Dependencies were installed when this app was scaffolded. On another machine, run:

```bash
composer install
```

Then run:

```bash
php artisan migrate
php artisan db:seed
```

The demo account is:

```text
Email: demo@helix.local
Password: password
```

## Useful Commands

```bash
php artisan serve
php artisan migrate:fresh --seed
php artisan test
```

## Search Functions

The migration `2026_05_05_000003_create_helix_search_functions.php` creates:

- `search_chunks(query_text, match_chatbot_id, match_count)` for PostgreSQL full-text retrieval
- `match_chunks(query_embedding, match_chatbot_id, match_count)` for vector similarity retrieval

Use `search_chunks` immediately after ingesting text chunks. Use `match_chunks` after your ingestion pipeline stores 768-dimension embeddings in `document_chunks.embedding`.
