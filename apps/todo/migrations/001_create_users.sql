create table users (
    id bigserial primary key,
    email text not null unique,
    password_hash text not null,
    created_at timestamptz not null default now()
);
