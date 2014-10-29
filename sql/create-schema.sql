create database talk2me;

use talk2me;

create table rooms (rooms_id int auto_increment not null, name tinytext not null, primary key(rooms_id));

create table messages (messages_id int auto_increment not null, rooms_id int not null, message text not null, primary key(messages_id));
