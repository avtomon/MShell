<?php

namespace avtomon;

class MShellException extends Exception
{
}

class MShell
{
    private $dbconnect; // Подключение к БД
    private $ttl; // Время жизни элемента кэша
    private $tagTtl; // Время жизни тега
    private $lockValue; // Значение заблокированного элемента кэша
    private $delay; // Задержка между последовательными попытками записи в кэш
    private $solt; // Соль, использующаяся при формировании имени ключа элемента кэша
    private $tryCount; // Колчичество попыток получение кэша
    private $lockTtl; // Время жизни блокировки
    private $mc; // Подключение к Memcached

    /**
     * MShell constructor.
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
            $this->mc = new \Redis;
            $connect = $this->mc->$connectType($hostOrSock, $port);
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
    
    public function __destruct()
    {
        if (get_class($this->mc) === 'Redis') {
            $this->mc->close();
        } else {
            $this->mc->quit();
        }
    }

    private function getTagsTimes(string $query, array $value) {
        $tags = filter_var($query, FILTER_VALIDATE_URL) ? ($value['tags'] ?? []) : $this->dbconnect->getTables($query);
        $tagsTimes = array_map(function ($tag) {
            return $this->mc->get($tag);
        }, $tags);

        if (!$tagsTimes) {
            throw new MShellException('Список тегов пуст');
        }

        return $tagsTimes;
    }

    /**
     * Возвращает данные
     *
     * @param $query - текст запроса
     * @param array $params - параментры запроса
     * @param int $expires - время актуальности кэшированного результата выполнения запроса
     *
     * @return mixed
     * @throws MShellException
     */
    public function getValue(string $query, array $params = [])
    {
        $tags = $this->dbconnect->getEditTables($query);
        if ($tags) {
            $this->initTags($tags);
            return $this->dbconnect->query($query, $params);
        }

        if (!$expires) {
            return $this->dbconnect->query($query, $params);
        }

        $key = $this->getKey($query . serialize($params));
        for ($i = 0; $i < $this->tryCount; $i++) {
            $value = $this->mc->get($key);
            if ($value === $this->lockValue) {
                usleep($this->delay);
                continue;
            }

            $tagsTimes = $this->getTagsTimes($query, $value);

            $value = json_decode($value, true);
            if (!$value || (!isset($value['time']) || $value['time'] < time()) || max(array_merge($tagsTimes, $value['time'])) !== $value['time']) {
                if (filter_var($query, FILTER_VALIDATE_URL)) {
                    return '';
                }

                $this->mc->set($key, $this->lockValue, $this->lockTtl);
                $value = $this->dbconnect->query($query, $params);
                $this->setValue($key, $value, $expires);
                return $value;
            }

            return $value['data'];
        }
        throw new MShellException('Не удалось установить блокировку');

    }

    /**
     * Инициализация заданного массива тегов
     *
     * @param array $tags - массив тегов
     *
     * @return bool
     * @throws MShellException
     */
    private function initTags(array & $tags = [])
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
     * @param $query - текст запроса
     *
     * @return string
     * @throws Exception
     */
    private function getKey(string $query)
    {
        if (!$query) {
            throw new MShellException('Строка запроса пуста');
        }

        return md5($query . $this->solt);
    }

    /**
     * Конкурентное сохранение значение в кэше
     *
     * @param $key - ключ элемента кэша
     * @param $value - значение для сохрания
     * @param $expires - время актуальности элемента кэша
     *
     * @return bool
     * @throws MShellException
     */
    private function setValue(string & $key, $value, array $tags = [])
    {
        if (!$key) {
            throw new MShellException('Значение не сохранено. Отсутствует ключ');
        }

        $value = ['data' => $value, 'time' => time()];
        if ($tags) {
            $value['tags'] = $tags;
        }
        if (!$this->mc->set($key, json_encode($value, JSON_UNESCAPED_UNICODE), $this->ttl)) {
            throw new MShellException('Не удалось сохранит значение');

        }

        return true;
    }

    /**
     * Сохранить разметку страницы в кэше
     *
     * @param $html - текст страница
     * @param $url - адрес страницы
     * @param $expire - на сколько сохранять
     *
     * @return bool
     */
    public function saveHTML(string & $html, string $url, array $params = [], int $expire = null, array $tags = [])
    {
        if (!$html) {
            throw new MShellException('Не передан HTML');
        }

        if (!$url || !filter_var($query, FILTER_VALIDATE_URL)) {
            throw new MShellException('Не передан верный URL');
        }

        if (is_null($expire)) {
            $expire = $this->ttl;
        }

        return $this->mc->setValue($this->getKey($url . serialize($params)), $html, $expire, $tags);
    }

    /**
     * Достать разметку страницы из кэша
     *
     * @param $url - адрес запрашиваемой страницы
     *
     * * @return bool
     */
    public function getHTML(string $url, array $params)
    {
        return $this->getValue($url, $params);
    }

    /**
     * Удалить страницу из кэша
     *
     * @param $url - адрес удаляемой страницы
     *
     * @return mixed
     */
    public function delHTML(string $url, array $params)
    {
        $this->mc->delete($this->getKey($url . serialize($params)));
    }

    /**
     * Удалить страницы из кэша
     *
     * @param array $urls - адрес удаляемых страниц
     *
     * @return bool
     */
    public function delHTMLs(array $urls, array $params = [])
    {
        $keys = array_map(function ($url, $params) {
            return $this->getKey($url . serialize($params));
        }, $urls, $params);

        if (get_class($this->mc) === 'Redis') {
            $this->mc->hSet(...$keys);
        } else {
            $this->mc->deleteMulti($keys);
        }
    }
}
