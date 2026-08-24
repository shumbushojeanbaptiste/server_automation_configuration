
# 3
docker compose down -v

# 4
docker compose up -d

# check the existing schemas
python -c "from app.core.database import Base; import app.models; print('\\n'.join(Base.metadata.tables.keys()))"
# 5
 rm -f migrations/versions/*.py if to restarting all madels

# 6
alembic revision --autogenerate -m "create tenants table"

# 7
alembic upgrade head

# 8
docker exec -it parking-postgres psql -U parking_user -d parking_db

# sample query in postgres

Use:

\dt

for psql.

Useful PostgreSQL commands you'll use constantly:

\dt

List tables.

\d tenants

Describe a table.

\di

List indexes.

\df

List functions.

\dn

List schemas.

\du

List database users/roles.

\l

List databases.

\q

Quit.