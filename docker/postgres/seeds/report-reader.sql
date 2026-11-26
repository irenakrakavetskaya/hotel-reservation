\getenv report_user REPORT_DB_USER
\getenv report_password REPORT_DB_PASSWORD

SELECT 1 FROM public.reservation LIMIT 0;

SELECT format(
    'CREATE ROLE %I LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT',
    :'report_user',
    :'report_password'
)
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'report_user')
\gexec

ALTER ROLE :"report_user"
    LOGIN PASSWORD :'report_password'
    NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT;

GRANT USAGE ON SCHEMA public TO :"report_user";
GRANT SELECT ON TABLE public.reservation TO :"report_user";
