#!/bin/sh
# Отдельная БД под тесты: make test не должен затирать данные разработки.
set -e
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    CREATE DATABASE gamestore_test OWNER $POSTGRES_USER;
EOSQL
