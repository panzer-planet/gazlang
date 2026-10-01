create table login_failures (
    id bigserial primary key,
    email text not null,
    failed_at timestamptz not null default now()
);

create index login_failures_email_failed_at on login_failures (email, failed_at);
