# Manually test ddtrace release candidates

1. Copy `.env.example` to `.env`
2. Change the env vars to the `package extension` artifact URLs
3. Rebuild the containers that you want to test
4. Run a container and visit [http://localhost:8080](http://localhost:8080)

**Do not forget to run some nice neighbor tests!**

## PHP-FPM

```bash
# Alpine (PHP-FPM) on PHP 7.4
$ docker-compose build alpine-7.4
$ PHP_FPM_CONTAINER=alpine-7.4 docker-compose up -d nginx

# Debian (PHP-FPM) on PHP 7.4
$ docker-compose build debian-7.4
$ PHP_FPM_CONTAINER=debian-7.4 docker-compose up -d nginx

# Debian (PHP-FPM) on PHP 8.0
$ docker-compose build debian-8.0
$ PHP_FPM_CONTAINER=debian-8.0 docker-compose up -d nginx

# CentOS 7 (PHP-FPM) on PHP 7.2
$ docker-compose build centos-7.2
$ PHP_FPM_CONTAINER=centos-7.2 docker-compose up -d nginx

# CentOS 7 (PHP-FPM) on PHP 5.5
$ docker-compose build centos-5.5
$ PHP_FPM_CONTAINER=centos-5.5 docker-compose up -d nginx

# CentOS 7 (PHP-FPM) on PHP 5.4
$ docker-compose build centos-5.4
$ PHP_FPM_CONTAINER=centos-5.4 docker-compose up -d nginx
```

To view PHP-FPM logs:

```bash
$ docker-compose logs -f <service-name>
```

## Apache

```bash
# Debian (Apache) on PHP 7.4
$ docker-compose build apache-7.4
$ docker-compose up -d apache-7.4
```

## PECL

```bash
# Amazon Linux (PECL + PHP-FPM) on PHP 7.2
$ docker-compose build pecl-7.2
$ PHP_FPM_CONTAINER=pecl-7.2 docker-compose up -d nginx

# Alpine (PECL + PHP-FPM) on PHP 5.6
$ docker-compose build pecl-5.6
$ PHP_FPM_CONTAINER=pecl-5.6 docker-compose up -d nginx

# Amazon Linux (PECL + PHP-FPM) on PHP 5.5
$ docker-compose build pecl-5.5
$ PHP_FPM_CONTAINER=pecl-5.5 docker-compose up -d nginx
```

## Wishlist

- [x] Add PHP 7.4 on Apache
- [x] Add PHP 5.6 PECL container
- [x] Add PHP 5.4 CentOS container
- [x] Add PHP 5.5 PECL container
- [x] Add PHP 8.0 on PHP-FPM
- [ ] Add PHP 8.0 on Apache
- [ ] Add PHP 5.4-zts PECL container
- [ ] Add PHP 5.6 CentOS container
- [ ] Add `php:7.4-apache` and install ZTS build of ddtrace
