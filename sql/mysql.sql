create table zpush_settings (key_name varchar(50) not null, key_value varchar(50) not null, created_at datetime not null, updated_at datetime not null, primary key (key_name));

create table zpush_users (username varchar(50) not null, device_id varchar(50) not null, created_at datetime not null, updated_at datetime not null, primary key (username, device_id));

create table zpush_states (id_state integer auto_increment, device_id varchar(50) not null, uuid varchar(50), state_type varchar(50), counter integer, state_data mediumblob,
            created_at datetime not null, updated_at datetime not null, primary key (id_state));

create unique index idx_zpush_states_unique on zpush_states (device_id, uuid, state_type, counter);

-- This is optional, and will require extra configuration in your mysql
-- http://www.mysqlperformanceblog.com/2012/05/30/data-compression-in-innodb-for-text-and-blob-fields/
alter table zpush_states engine=InnoDB row_format=compressed key_block_size=16;


-- This table has a primary key id integer, because I will be linking a Rails model against it (admin wui)
create table zpush_preauth_users (id integer auto_increment, username varchar(50) not null, device_id varchar(50) not null, authorized boolean not null,
            created_at datetime not null, updated_at datetime not null, primary key (id));

create unique index index_zpush_preauth_users_on_username_and_device_id on zpush_preauth_users (username, device_id);

create table zpush_combined_usermap (
  username varchar(50) not null,
  backend varchar(32) not null,
  mappedname varchar(200) not null,
  created_at datetime not null,
  updated_at datetime not null,
  primary key (username, backend)
);
