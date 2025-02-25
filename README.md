# bitrix-url-checker
# UrlDetector – сканер битых ссылок под CMS Битрикс

Класс предназначен для фоновой проверки списка URL-адресов с отсеиванием тех, которые отвечают не со статусом `200 OK`.  

Расширяется от инструментария ядра Stepper, позволяющий выполнять многошаговые задачи, разбивая их выполнение на шаги/порции.  

## Возможности
- Проверка ссылок в фоновом режиме.
- Логирование результатов в файл.
- Вывод списка "битых" ссылок в удобном HTML-формате.

## Установка
1. Скопируйте файл `UrlDetector.php` в ваш проект.
2. Подключите класс в нужном месте:
   ```php
   use AlexeyGfi\CatalogHelpers\UrlDetector;
   ```
3. Зарядите проверку на выполнение (либо по событию либо через агентов/крон):
   ```php
   UrlDetector::bindChain();
   ```

## Принцип работы
1. **Запуск через `bindChain()`** – класс начинает фоновую обработку списка URL-ов.  
2. **Метод `execute()`** – шаг за шагом проверяет ссылки и записывает результат

## Получение результатов
Выполняем метод 
```php
\AlexeyGfi\CatalogHelpers\UrlDetector::renderErrorStat();
```
...в интерфейсе выполнения пхп-кода (CMS Битрикс / Администрирование / Инструменты / Коммандная PHP-строка), либо организовываем СЕО-шникам отдельную страницу с вызовом данного кода.
