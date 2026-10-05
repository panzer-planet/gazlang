alter table login_failures add column address text;

create index login_failures_address_failed_at on login_failures (address, failed_at);
