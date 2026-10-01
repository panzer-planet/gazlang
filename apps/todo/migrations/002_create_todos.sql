create table todos (
    id bigserial primary key,
    user_id bigint not null references users (id) on delete cascade,
    title text not null check (length(title) between 1 and 200),
    done boolean not null default false,
    created_at timestamptz not null default now()
);

create index todos_user_id_created_at on todos (user_id, created_at desc);
