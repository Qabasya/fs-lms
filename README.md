
# Плагин LMS

## Установка
1. Скачай репозиторий
2. В корневой директории, где находится папка с проектом, создай: docker/dev/Dockerfile
3. В корневой директории создай файл docker-compose
4. В ней запусти терминал с командой:
```
docker compose up -d --build
```

Содержимое Dockerfile:
```
FROM node:20

RUN apt-get update \
 && apt-get install -y \
    php-cli \
    curl \
    git \
    unzip

# установка composer
RUN curl -sS https://getcomposer.org/installer | php \
 && mv composer.phar /usr/local/bin/composer

WORKDIR /var/www/plugin
```
Содержимое docker-compose:
```
version: '3.8'

services:

  db:
    image: mariadb:10.6
    container_name: wp_db
    restart: always
    environment:
      MYSQL_ROOT_PASSWORD: root
      MYSQL_DATABASE: wordpress
    volumes:
      - db_data:/var/lib/mysql

  wordpress:
    image: wordpress:latest
    container_name: wp_app
    depends_on:
      - db
      - mailpit
    ports:
      - "8080:80"
    restart: always
    environment:
      WORDPRESS_DB_HOST: db:3306
      WORDPRESS_DB_USER: root
      WORDPRESS_DB_PASSWORD: root
      WORDPRESS_DB_NAME: wordpress
      WORDPRESS_DEBUG: 1
    volumes:
      - ./wordpress:/var/www/html

  phpmyadmin:
    image: phpmyadmin:latest
    container_name: wp_phpmyadmin
    restart: always
    depends_on:
      - db
    ports:
      - "8081:80"
    environment:
      PMA_HOST: db
      PMA_PORT: 3306
      MYSQL_ROOT_PASSWORD: root

  mailpit:
    image: axllent/mailpit
    container_name: wp_mailpit
    restart: always
    ports:
      - "8025:8025"
      - "1025:1025"

volumes:
  db_data:
```

Структура проекта:
```
Корень
├── 📁 docker
│   └── 📁 dev
│       └── Dockerfile
├── 📁 fs-lms
└── docker-compose
```

## Сервисы контейнера

Wordpress -> localhost:8080

PhpMyAdmin -> localhost:8081

    server: db
    user: root
    password: root


Mailpit -> localhost:8025