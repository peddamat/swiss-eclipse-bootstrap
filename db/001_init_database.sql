create database @DATABASE@ character set utf8; 
grant all privileges on @DATABASE@.* to '@DATABASE_USERNAME@'@'localhost' identified by '@DATABASE_PASSWORD@';
