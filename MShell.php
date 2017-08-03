<?php

class MShellException extends Exception
{
}

class MShell
{
    private $dbconnect; // Время жизни элемента кэша
    private $ttl; // Время жизни тега
    private $tagTtl; // Значение заблокированного элемента кэша
    private $lockValue; // Задержка между последовательными попытками записи в кэш
    private $delay; // Соль, использующаяся при формировании имени ключа элемента кэша
    private $solt; // Подключение к Memcached
    private $mc;

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
            string $lockValue
    ) {
        $connect = NULL;
        if ($cacheType == 'memcached') {
            $this->mc = new Memcached;
            $connect = $this->mc->addServer($hostOrSock, $port);
        } elseif ($cacheType == 'redis') {
            $this->mc = new Redis;
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
    }
    
    public function __destruct()
    {
        if (get_class($this->mc) === 'Redis') {
            $this->mc->close();
        } else {
            $this->mc->quit();
        }
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
    public function getValue(string $query, array $params = [], int $expires = 120)
    {
        $tags = $this->dbconnect->getEditTables($query);
        if ($tags) {
            $this->initTags($tags);
            return $this->dbconnect->query($query, $params);
        } else {
            if ($expires) {
                $key = $this->getKey($query . serialize($params));
                for ($i = 0; $i < 20; $i++) {
                    $value = $this->mc->get($key);
                    if ($value) {
                        if ($value !== $this->lockValue) {
                            $value = json_decode($value, true);
                            if (!isset($value['ttl']) || $value['ttl'] < time()) {
                                goto get;
                            } elseif ($tags = $this->dbconnect->getTables($query)) {
                                foreach ($tags as & $tag) {
                                    if ($this->mc->get($tag) >= $value['ttl'] - $expires) {
                                        goto get;
                                    }
                                }
                                unset($tag);
                                return $value['data'];
                            } else {
                                throw new MShellException('Список таблиц запроса на выборку пуст');
                            }
                        } else {
                            usleep($this->delay);
                        }
                    } else {
                        get:
                        $value = $this->dbconnect->query($query, $params);
                        $this->setValue($key, $value, $expires);
                        return $value;
                    }
                }
                throw new MShellException('Не удалось установить блокировку');
            }
            return $this->dbconnect->query($query, $params);
        }
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
        if ($tags) {
            foreach ($tags as & $tag) {
                if (!$this->mc->set($tag, time(), $this->tagTtl)) {
                    throw new MShellException('Не удалось установить значение тега');
                }
            }
            unset($tag);
            return true;
        } else {
            throw new MShellException('Теги отсутствуют');
        }
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
        if ($query) {
            return md5($query . $this->solt);
        } else {
            throw new MShellException('Строка запроса пуста');
        }
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
    private function setValue(string & $key, $value, int & $expires)
    {
        if ($key) {
            $value = array('data' => $value, 'ttl' => time() + $expires);
            if ($this->mc->set($key, json_encode($value, JSON_UNESCAPED_UNICODE), $this->ttl)) {
                return true;
            } else {
                throw new MShellException('Не удалось сохранит значение');
            }
        } else {
            throw new MShellException('Значение не сохранено. Отсутствует ключ');
        }
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
    public function saveHTML(string & $html, string $url, int $expire)
    {
        return $this->mc->set(md5($url), $html, $expire);
    }

    /**
     * Достать разметку страницы из кэша
     *
     * @param $url - адрес запрашиваемой страницы
     *
     * * @return bool
     */
    public function getHTML(string $url)
    {
        return $this->mc->get(md5($url));
    }

    /**
     * Удалить страницу из кэша
     *
     * @param $url - адрес удаляемой страницы
     *
     * @return mixed
     */
    public function delHTML(string $url)
    {
        $this->mc->delete(md5($url));
    }

    /**
     * Удалить страницы из кэша
     *
     * @param array $urls - адрес удаляемых страниц
     *
     * @return bool
     */
    public function delHTMLs(array $urls)
    {
        if (get_class($this->mc) === 'Redis') {
            $this->mc->hSet(...$urls);
        } else {
            $this->mc->deleteMulti($urls);
        }
    }

}