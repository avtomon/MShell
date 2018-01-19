<?php

namespace avtomon;

class MShellException extends \Exception
{
}

class MShell
{

    /**
     * Объект подключения к базе данных
     *
     * @var _PDO
     */
    private $dbconnect;

    /**
     * Время жизни элемента кэша
     *
     * @var int
     */
    private $ttl;

    /**
     * Время жизни тега
     *
     * @var int
     */
    private $tagTtl;

    /**
     * Значение заблокированного элемента кэша
     *
     * @var string
     */
    private $lockValue;

    /**
     * Задержка между последовательными попытками записи в кэш
     *
     * @var int
     */
    private $delay;

    /**
     * Соль, использующаяся при формировании имени ключа элемента кэша
     *
     * @var string
     */
    private $solt;

    /**
     * Колчичество попыток получение кэша
     *
     * @var int
     */
    private $tryCount;

    /**
     * Время жизни блокировки
     *
     * @var int
     */
    private $lockTtl;

    /**
     * Подключение к Memcached|Redis
     *
     * @var \Redis|\Memcached
     */
    private $mc;

    /**
     * MShell constructor
     *
     * @param _PDO $dbconnect
     * @param string $cacheType
     * @param string $connectType
     * @param string $hostOrSock
     * @param int $port
     * @param int $ttl
     * @param int $tagTtl
     * @param int $delay
     * @param string $solt
     * @param string $lockValue
     * @param int $tryCount
     * @param int $lockTtl
     *
     * @throws MShellException
     */
    public function __construct(
        _PDO $dbconnect,
        string $cacheType,
        string $connectType,
        string $hostOrSock,
        int $port,
        int $ttl,
        int $tagTtl,
        int $delay,
        string $solt,
        string $lockValue,
        int $tryCount,
        int $lockTtl
    ) {
        $connect = NULL;
        if ($cacheType == 'memcached') {
            $this->mc = new \Memcached;
            $connect = $this->mc->addServer($hostOrSock, $port);
        } elseif ($cacheType == 'redis') {
            if (!in_array($connectType, ['connect', 'pconnect'])) {
                throw new MShellException('Тип подключения должен быть pconnect или connect');
            }

            $this->mc = new \Redis;
            $connect = $this->mc->$connectType($hostOrSock, $port);
        } else {
            throw new MShellException("Кэшировать можно только с использованием Memcached и Redis. Вы пытаетесь использовать $cacheType");
        }

        if (!$connect) {
            throw new MShellException('Подключение не удалось');
        }

        $this->dbconnect = $dbconnect;
        $this->ttl = $ttl;
        $this->tagTtl = $tagTtl;
        $this->lockValue = $lockValue;
        $this->delay = $delay;
        $this->solt = $solt;
        $this->tryCount = $tryCount;
        $this->lockTtl = $lockTtl;
    }

    /**
     * Деструктор
     */
    public function __destruct()
    {
        if (get_class($this->mc) === 'Redis') {
            $this->mc->close();
        } else {
            $this->mc->quit();
        }
    }

    /**
     * Возвращает массив времен актуальности тегов асоциированных с запросом
     *
     * @param string $query - текст запроса
     * @param array $value - занчение, хранимое по этому запросу
     *
     * @return array
     */
    private function getTagsTimes(string $query, array $value = []): array
    {
        $tags = filter_var($query, FILTER_VALIDATE_URL) ? ($value['tags'] ?? []) : $this->dbconnect->getTables($query);
        $tagsTimes = array_map(function ($tag) {
            return $this->mc->get($tag);
        }, $tags);

        return $tagsTimes;
    }

    /**
     * Возвращает данные
     *
     * @param string $query - текст запроса
     * @param array $params - параментры запроса
     *
     * @return mixed
     *
     * @throws MShellException
     */
    public function getValue(string $query, array $params = [])
    {
        $tags = $this->dbconnect->getEditTables($query);
        if ($tags) {
            $this->initTags($tags);
            return $this->dbconnect->query($query, $params);
        }

        $key = $this->getKey($query . serialize($params));
        for ($i = 0; $i < $this->tryCount; $i++) {
            $value = $this->mc->get($key);
            if ($value === $this->lockValue) {
                usleep($this->delay);
                continue;
            }

            $value = json_decode($value, true);
            $tagsTimes = $this->getTagsTimes($query, $value ?: []);

            if ($value && !empty($value['time']) && $value['time'] >= time() && min(array_merge($tagsTimes, [$value['time']])) === $value['time']) {
                return $value['data'];
            }

            if (filter_var($query, FILTER_VALIDATE_URL)) {
                return '';
            }

            $this->mc->set($key, $this->lockValue, $this->lockTtl);
            $value = $this->dbconnect->query($query, $params);
            $this->setValue($key, $value, $this->dbconnect->getTables($query));

            return $value;
        }

        throw new MShellException('Не удалось установить блокировку');
    }

    /**
     * Инициализация заданного массива тегов
     *
     * @param array $tags - массив тегов
     *
     * @return bool
     *
     * @throws MShellException
     */
    private function initTags(array & $tags = []): bool
    {
        if (!$tags) {
            throw new MShellException('Теги отсутствуют');
        }

        foreach ($tags as & $tag) {
            if (!$this->mc->set($tag, time(), $this->tagTtl)) {
                throw new MShellException('Не удалось установить значение тега');
            }
        }

        unset($tag);

        return true;
    }

    /**
     * Функция получения ключа элемента кэша
     *
     * @param string $query - текст запроса
     *
     * @return string
     *
     * @throws Exception
     */
    private function getKey(string $query): string
    {
        if (!$query) {
            throw new MShellException('Строка запроса пуста');
        }

        return md5($query . $this->solt);
    }

    /**
     * Конкурентное сохранение значение в кэше
     *
     * @param string $key - ключ элемента кэша
     * @param mixed $value - значение для сохрания
     * @param array $tags - время актуальности элемента кэша
     *
     * @return bool
     *
     * @throws MShellException
     */
    private function setValue(string &$key, $value, array $tags = []): bool
    {
        if (!$key) {
            throw new MShellException('Значение не сохранено. Отсутствует ключ');
        }

        $value = ['data' => $value, 'time' => time()];
        if ($tags) {
            $value['tags'] = $tags;
        }

        if (empty($this->mc->set($key, json_encode($value, JSON_UNESCAPED_UNICODE), $this->ttl))) {
            throw new MShellException('Не удалось сохранить значение');

        }

        return true;
    }

    /**
     * Сохранить разметку страницы в кэше
     *
     * @param string $url - адрес страницы
     * @param array $params - GET-параметры запроса
     * @param string $html - текст страницы
     * @param array $tags - теги страницы
     *
     * @return bool
     */
    public function saveHTML(string $url, array &$params = [], string &$html, array &$tags = []): bool
    {
        if (!$html) {
            throw new MShellException('Не передан HTML');
        }

        if (!$url || !filter_var($query, FILTER_VALIDATE_URL)) {
            throw new MShellException('Не передан верный URL');
        }

        return $this->mc->setValue($this->getKey($url . serialize($params)), $html, $tags);
    }

    /**
     * Достать разметку страницы из кэша
     *
     * @param string $url - адрес запрашиваемой страницы
     * @param array $params - параметры страницы
     *
     * @return string
     *
     * @throws MShellException
     */
    public function getHTML(string $url, array &$params): string
    {
        return $this->getValue($url, $params);
    }

    /**
     * Удалить страницу из кэша
     *
     * @param $url - адрес удаляемой страницы
     * @param array $params - параметры страницы
     *
     * @return bool
     */
    public function delHTML(string $url, array $params): bool
    {
        return $this->mc->delete($this->getKey($url . serialize($params)));
    }

    /**
     * Удалить страницы из кэша
     *
     * @param array $urls - адрес удаляемых страниц
     * @param array $params - параметры страницы
     *
     * @return bool
     */
    public function delHTMLs(array $urls, array $params = []): bool
    {
        $keys = array_map(function ($url, $params) {
            return $this->getKey($url . serialize($params));
        }, $urls, $params);

        if (get_class($this->mc) === 'Redis') {
            return $this->mc->hSet(...$keys);
        } else {
            return $this->mc->deleteMulti($keys);
        }
    }

    /**
     * Возвращает подключение к РСУБД
     *
     * @return _PDO
     */
    public function getDbconnect(): ?_PDO
    {
        return $this->dbconnect;
    }
}
