create table settings (key_name varchar(50) not null, key_value varchar(50) not null, created_at datetime not null, updated_at datetime not null, primary key (key_name));

create table users (username varchar(50) not null, device_id varchar(50) not null, created_at datetime not null, updated_at datetime not null, primary key (username, device_id));

create table states (id_state integer auto_increment, device_id varchar(50) not null, uuid varchar(50), state_type varchar(50), counter integer, state_data text not null,
            created_at datetime not null, updated_at datetime not null, primary key (id_state));

create unique index idx_states_unique on states (device_id, uuid, state_type, counter);