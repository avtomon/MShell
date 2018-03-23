<?php

namespace avtomon;

class MShellException extends \Exception
{
}

class MShell
{
    const URL_TEMPLATE = '/^.+?\/[^\/]+$/';

    /**
     * Объект подключения к базе данных
     *
     * @var _PDO
     */
    protected $dbconnect;

    /**
     * Время жизни элемента кэша
     *
     * @var int
     */
    protected $ttl;

    /**
     * Время жизни тега
     *
     * @var int
     */
    protected $tagTtl;

    /**
     * Значение заблокированного элемента кэша
     *
     * @var string
     */
    protected $lockValue;

    /**
     * Задержка между последовательными попытками записи в кэш
     *
     * @var int
     */
    protected $delay;

    /**
     * Соль, использующаяся при формировании имени ключа элемента кэша
     *
     * @var string
     */
    protected $solt;

    /**
     * Колчичество попыток получение кэша
     *
     * @var int
     */
    protected $tryCount;

    /**
     * Время жизни блокировки
     *
     * @var int
     */
    protected $lockTtl;

    /**
     * Подключение к Memcached|Redis
     *
     * @var \Redis|\Memcached
     */
    protected $mc;

    /**
     * MShell constructor
     *
     * @param _PDO $dbconnect
     * @param string $cacheType
     * @param \Memcached|\Redis $connect - подключение к кэшу
     *
     * $this->mc = new \Memcached;
     * $connect = $this->mc->addServer($hostOrSock, $port);
     * OR
     * $this->mc = new \Redis;
     * $connect = $this->mc->$connectType($hostOrSock, $port);
     *
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
        $connect,
        int $ttl,
        int $tagTtl,
        int $delay,
        string $solt,
        string $lockValue,
        int $tryCount,
        int $lockTtl
    )
    {
        if (!$connect) {
            throw new MShellException('Не передано подключение к кэшу');
        }

        if (!($connect instanceof \Redis) && !($connect instanceof \Memcached)) {
            throw new MShellException("Кэшировать можно только с использованием Memcached и Redis");
        }

        $this->dbconnect = $dbconnect;
        $this->ttl = $ttl;
        $this->tagTtl = $tagTtl;
        $this->lockValue = $lockValue;
        $this->delay = $delay;
        $this->solt = $solt;
        $this->tryCount = $tryCount;
        $this->lockTtl = $lockTtl;
        $this->mc = $connect;
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
    protected function getTagsTimes(string $query, array $value = []): array
    {
        if (preg_match(self::URL_TEMPLATE, $query)) {
            $tags = $value['tags'] ?? [];
        } else {
            if (!$this->dbconnect) {
                throw new MShellException('Отсутствует подключение к базе данных');
            }

            $tags = $this->dbconnect->getTables($query);
        }

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
        if ($this->dbconnect) {
            $tags = $this->dbconnect->getEditTables($query);
            if ($tags) {
                $this->initTags($tags);
                return $this->dbconnect->query($query, $params);
            }
        }

        $key = $this->getKey($query . serialize($params));

        $parseValue = function ($value) use ($query) {
            if (empty($value)) {
                return null;
            }

            if ($value = json_decode($value, true)) {
                return null;
            }

            $tagsTimes = $this->getTagsTimes($query, $value ?: []);

            if (!empty($value['time']) && $value['time'] >= time() && min(array_merge($tagsTimes, [$value['time']])) === $value['time']) {
                if (preg_match(self::URL_TEMPLATE, $query)) {
                    return $value;
                }

                return $value['data'];
            }

            if (preg_match(self::URL_TEMPLATE, $query)) {
                return '';
            }

            return null;
        };

        $save = function () use ($key, $query, $params) {
            if (!$this->mc->set($key, $this->lockValue, $this->lockTtl)) {
                return null;
            }

            $value = $this->dbconnect->query($query, $params);
            if (!$this->setValue($key, $value, $this->dbconnect->getTables($query))) {
                throw new MShellException('Не удалось сохранить данные в кэше');
            }

            return $value;
        };

        for ($i = 0; $i < $this->tryCount; $i++) {
            $value = $this->mc->get($key);
            if ($value === $this->lockValue) {
                usleep($this->delay);
                continue;
            }

            if (!$this->dbconnect) {
                return $parseValue($value);
            }

            if ($result = $parseValue($value)) {
                return $result;
            }

            if (is_null($result = $save())) {
                continue;
            }

            return $result;
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
    protected function initTags(array & $tags = []): bool
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
    protected function getKey(string $query): string
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
    protected function setValue(string &$key, $value, array $tags = []): bool
    {
        if (!$key) {
            throw new MShellException('Значение не сохранено. Отсутствует ключ');
        }

        $value = ['data' => $value, 'time' => time()];
        if ($tags) {
            $value['tags'] = $tags;
        }

        if (empty($this->mc->set($key, json_encode($value, JSON_UNESCAPED_UNICODE), $this->ttl))) {
            return false;
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

        if (!$url || !preg_match(self::URL_TEMPLATE, $query)) {
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
    public function getHTML(string $url, array &$params, string $checkFile = ''): string
    {
        if (!$url || !preg_match(self::URL_TEMPLATE, $query)) {
            throw new MShellException('Не передан верный URL');
        }

        $html = $this->getValue($url, $params);
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
    public function getDbConnect(): ?_PDO
    {
        return $this->dbconnect;
    }
}
