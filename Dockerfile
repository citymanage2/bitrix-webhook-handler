FROM php:8.2-cli

WORKDIR /app

# Копируем наши файлы внутрь контейнера
COPY . /app

# Устанавливаем нужные PHP-расширения (если потребуется)
RUN docker-php-ext-install curl

# Запускаем встроенный веб-сервер PHP на порту 10000
CMD ["php", "-S", "0.0.0.0:10000", "index.php"]
