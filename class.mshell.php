<?php

class MShellException extends Exception { }

class MShell
{
    private $dbconnect; // Подключение в БД
    private $ttl; // Время жизни элемента кэша
    private $tag_ttl; // Время жизни тега
    private $lock_value; // Значение заблокированного элемента кэша
    private $delay; // Задержка между последовательными попытками записи в кэш
    private $solt; // Соль, использующаяся при формировании имени ключа элемента кэша
    private $mc; // Подключение к Memcached

    private static $instance = false;

    /**
     * Синглтон для получения объекта управления memcached
     *
     * @param string $hostorsock - сокет или хост для подключения в Memcached
     * @param int $port - Порт для подключения
     * @param int $ttl - Время жизни элемента кэша
     * @param int $tag_ttl - Время жизни тега
     * @param int $delay - Задержка между последовательными попытками записи в кэш
     * @param bool $solt - Соль, использующаяся при формировании имени ключа элемента кэша
     *
     * @return bool|MShell
     * @throws MShellException
     */
    public static function create (string $cache_type = MSHELL_CACHE_TYPE,
                                   string $connect_type = MSHELL_CONNECT_TYPE,
                                   string $hostorsock = MSHELL_SOCKET,
                                   int $port = MSHELL_PORT,
                                   int $ttl = MSHELL_TTL,
                                   int $tag_ttl = MSHELL_TAG_TTL,
                                   int $delay = MSHELL_DELAY,
                                   string $solt = MSHELL_SOLT)
    {
        if (!self::$instance)
        {
            $dbconnect = _PDO::create();

            if ($cache_type == 'memcached')
            {
                $cache = new Memcached;
                $connect = $cache->addServer($hostorsock, $port);
            }
            elseif ($cache_type == 'redis')
            {
                $cache = new Redis;
                $connect = $cache->$connect_type($hostorsock, $port);
            }
            if ($connect)
            {
                self::$instance = new MShell ($cache, $ttl, $tag_ttl, $delay, $solt, $dbconnect);
            }
            else
            {
                throw new MShellException('Подключение не удалось');
            }
        }
        return self::$instance;
    }

    /**
     * Получить уже созданный экземпляр класса
     *
     * @return bool
     */
    public static function getInstance ()
    {
        return self::$instance;
    }

    /**
     * Конструктор класса
     *
     * @param $mc - объект подключения к memcached
     * @param $ttl - время актуальности элемента кэша
     * @param $tag_ttl - время актуальности тега
     * @param $delay - задержка между двумя последовательными запросами блокировки элемента кэша
     * @param $solt - соль для генерации ключа элемента
     * @param $dbconnect - объект подключения к БД
     */
    private function __construct (Memcached & $mc, int & $ttl, int & $tag_ttl, int & $delay, string & $solt, _PDO & $dbconnect)
    {
        $this->dbconnect = $dbconnect;
        $this->mc = $mc;
        $this->ttl = $ttl;
        $this->tag_ttl = $tag_ttl;
        $this->lock_value = MSHELL_LOCK_VALUE;
        $this->delay = $delay;
        $this->solt = $solt;
        $this->dbconnect = $dbconnect;      
    }

    /**
     * Деструктор класса
     */
    public function __destruct ()
    {
        if (get_class($this->mc) === 'Redis')
            return $this->mc->close();
        else
            return $this->mc->quit();
    }

    /**
     * Функция получения ключа элемента кэша
     *
     * @param $query - текст запроса
     *
     * @return string
     * @throws Exception
     */
    private function getKey (string $query)
    {
        if ($query)
        {
            return md5($query . $this->solt);
        }
        else
        {
            throw new MShellException('Строка запроса пуста');
        }
    }

    /**
     * Начать транзакцию
     *
     * @return mixed
     */
    public function beginTransaction ()
    {
        return $this->dbconnect->beginTransaction();
    }

    /**
     * Зафиксировать транзакцию
     *
     * @return mixed
     */
    public function commit ()
    {
        return $this->dbconnect->commit();
    }

    /**
     * Откатить транзакцию
     *
     * @return mixed
     */
    public function rollBack ()
    {
        return $this->dbconnect->rollBack();
    }

    /**
     * Возвращает данные
     *
     * @param $query - текст запроса
     * @param array $params - параментры запроса
     * @param int $expires - время актуальности кэшированного результата выполнения запроса
     *
     * @return mixed
     * @throws Exception
     */
    public function getValue (string $query, array $params = [], int $expires = 120)
    {
        $tags = $this->dbconnect->getEditTables($query);
        if ($tags)
        {
            $this->initTags($tags);
            return $this->dbconnect->query($query, $params);
        }
        else
        {
            if ($expires)
            {
                $key = $this->getKey($query . serialize($params));
                for ($i = 0; $i < 20; $i++)
                {
                    $value = $this->mc->get($key);
                    if ($value)
                    {
                        if ($value !== $this->lock_value)
                        {
                            $value = json_decode($value, true);
                            if (!isset($value['ttl']) || $value['ttl'] < time())
                            {
                                goto get;
                            }
                            elseif ($tags = $this->dbconnect->getTables($query))
                            {
                                foreach ($tags as & $tag)
                                {
                                    if ($this->mc->get($tag) >= $value['ttl'] - $expires)
                                    {
                                        goto get;
                                    }
                                }
                                unset($tag);
                                return $value['data'];
                            }
                            else
                            {
                                throw new MShellException('Список таблиц запроса на выборку пуст');
                            }
                        }
                        else
                        {
                            usleep($this->delay);
                        }
                    }
                    else
                    {
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
     * @throws Exception
     */
    private  function initTags (array & $tags = [])
    {
        if ($tags)
        {
            foreach ($tags as & $tag)
            {
                if (!$this->mc->set($tag, time(), $this->tag_ttl))
                    throw new MShellException('Не удалось установить значение тега');
            }
            unset($tag);
            return true;
        }
        else
        {
            throw new MShellException('Теги отсутствуют');
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
    private function setValue (string & $key, $value, int & $expires)
    {
        if ($key)
        {
            $value = array('data' => $value, 'ttl' => time() + $expires);
            if ($this->mc->set($key, json_encode($value, JSON_UNESCAPED_UNICODE), 0, $this->ttl))
            {
                return true;
            }
            else
            {
                throw new MShellException('Не удалось сохранит значение');
            }
        }
        else
        {
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
    public function getHTML (string $url)
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
    public function delHTML (string $url)
    {
        return $this->mc->delete(md5($url));
    }

    /**
     * Удалить страницы из кэша
     *
     * @param array $urls - адрес удаляемых страниц
     *
     * @return bool
     */
    public function delHTMLs (array $urls)
    {
        return $this->mc->deleteMulti($urls);
    }

}