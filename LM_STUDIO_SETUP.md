# LM Studio Integration Setup

## Настройка таймаутов для больших переводов

### 1. PHP-FPM (обычно `/etc/php/8.x/fpm/php.ini` или `/etc/php.ini`)

```ini
max_execution_time = 3000
max_input_time = 3000
```
### 1.2 /etc/php/x.x/fpm/pool.d/www.conf
```conf
request_terminate_timeout = 3000
```

После изменений перезапустите PHP-FPM:
```bash
sudo systemctl restart phpx.x-fpm  # или php-fpm
```

### 2. Nginx (обычно `/etc/nginx/sites-available/your-site`)

```nginx
location ~ \.php$ {
    fastcgi_read_timeout 3000;
    fastcgi_send_timeout 3000;
    # ... остальные настройки fastcgi
}
```

После изменений перезапустите Nginx:
```bash
sudo nginx -t  # проверка конфигурации
sudo systemctl reload nginx
```

### 3. WordPress (добавьте в `wp-config.php`)

```php
// Увеличить лимит времени для Ajax-запросов
define('WP_MEMORY_LIMIT', '256M');
set_time_limit(3000);
```

## Настройка LM Studio в плагине

1. Перейдите в **Loco Translate → Settings → API keys**
2. Найдите секцию **LM Studio**
3. Настройте:
   - **Endpoint**: `http://tunnel_ip:1234/v1/chat/completions`
   - **Model**: название вашей модели (например, `google/gemma-2-9b`)
   - **API key**: оставьте пустым (если не требуется)
   - **Prompt**: (опционально) `You must translate every single item, do not skip any`
   - **Timeout**: 120 секунд (увеличьте до 180-300 для медленных моделей)

## Рекомендации по моделям

### Для быстрого перевода:
- Gemma 2B/7B
- Llama 3.2 3B
- Qwen 2.5 7B

### Для качественного перевода:
- Gemma 2 9B/27B
- Llama 3.1 8B
- Qwen 2.5 14B/32B

### Настройки LM Studio:
- **Context Length**: минимум 4096 токенов
- **GPU Layers**: максимум для вашей видеокарты
- **Temperature**: 0.3 (по умолчанию в плагине)

## Использование

1. Откройте файл перевода в редакторе Loco Translate
2. Нажмите кнопку **Auto translate** (или выберите строки и используйте batch translate)
3. Выберите **LM Studio** из списка провайдеров
4. Дождитесь завершения перевода

## Производительность

- **Малые задачи** (< 50 строк): ~1-2 минуты
- **Средние задачи** (50-200 строк): ~3-10 минут
- **Большие задачи** (> 200 строк): может потребоваться несколько запусков

Для больших файлов рекомендуется переводить частями:
1. Отфильтруйте непереведённые строки
2. Выберите 50-100 строк
3. Запустите автоперевод
4. Повторите для следующей порции

## Устранение проблем

### cURL error 28: Operation timed out
- Увеличьте **Timeout** в настройках LM Studio (180-300 секунд)
- Используйте более быструю модель или уменьшите её размер
- Увеличьте GPU layers в LM Studio для ускорения
- Проверьте загрузку системы (CPU/GPU/RAM)
- Переводите меньшими порциями (20-30 строк за раз)

### Модель возвращает неполные переводы
- Увеличьте `max_tokens` в LM Studio (Settings → Server → Max Tokens)
- Используйте не-reasoning модель
- Добавьте в промпт: `Translate ALL items without skipping`

### Модель генерирует объяснения вместо JSON
- Добавьте в промпт: `Return ONLY JSON array, no explanations`
- Используйте модель с лучшей instruction-following способностью
- Попробуйте temperature = 0.1

### Переводы низкого качества
- Используйте более крупную модель
- Добавьте контекст в промпт (например, "This is a WordPress theme")
- Проверьте что модель поддерживает целевой язык

## Debug

Для просмотра логов перевода:
1. Включите WordPress debug: `define('WP_DEBUG', true); define('WP_DEBUG_LOG', true);`
2. Логи будут в `wp-content/debug.log`
3. Ищите строки с `[Loco.debug]`

Логи LM Studio показывают все запросы и ответы в реальном времени.
