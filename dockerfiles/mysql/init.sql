create table users (
    id integer not null primary key auto_increment,
    email varchar(100) not null unique,
    name varchar(100),
    password varchar(100),
    remember_token varchar(100),
    updated_at timestamp,
    created_at timestamp
);
