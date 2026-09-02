# gamestore-core

Ядро магазина цифровых товаров: заказы, платёжные вебхуки, однократная автоматическая выдача.
Тестовое задание. Требования — в [SPEC.md](SPEC.md), исходная постановка — в [docs/TASK.md](docs/TASK.md).

## Стек

| Компонент | Что это |
|---|---|
| PHP 8.3 + Laravel 12 | приложение |
| PostgreSQL 16 | единственный источник правды по идемпотентности |
| Redis | драйвер очередей |
| Docker Compose | `app`, `worker`, `scheduler`, `supplier-stub`, `nginx`, `postgres`, `redis` |
| Pest | тесты |

Магазин и заглушка поставщика — одна кодовая база, но **разные контейнеры**: так таймауты
поставщика ловятся честно, по сети. Какие роуты поднимать, решает `APP_ROLE`
(`app` | `stub` | `all`; `all` — только для тестов).

| URL | Что отвечает |
|---|---|
| http://localhost:8080 | REST API магазина |
| http://localhost:8090 | заглушка поставщика |

## Запуск

```bash
cp .env.example .env
sed -i "s/^UID=.*/UID=$(id -u)/; s/^GID=.*/GID=$(id -g)/" .env
make up          # поднять стек + миграции
make seed        # каталог и пул ключей
make test        # тесты
```

`UID`/`GID` в `.env` нужны, чтобы php-fpm внутри контейнера писал в примонтированный
`storage/` от имени хостового пользователя и не оставлял root-файлов в рабочей копии.

Без `make` те же шаги — напрямую:

```bash
docker compose up -d --build
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan test
```

## Тесты

Тесты гоняются по **PostgreSQL** (отдельная БД `gamestore_test`, создаётся при первом
подъёме `postgres`), а не по sqlite: инварианты держатся на частичных индексах, `jsonb`,
`numeric` и `FOR UPDATE SKIP LOCKED` — на sqlite это не проверяется.

## Состояние

| Этап SPEC | Статус |
|---|---|
| 0. Bootstrap: каркас, Docker, БД, тесты | готово |
| 1. Ядро API + авто-выдача | в работе |
| 2. Exactly-once под гонками | — |
| 3. Устойчивые интеграции, таймаут ≠ отказ | — |
| 4. Сверка, логи, восстановление | — |
| 5. Каталог под нагрузкой | — |
