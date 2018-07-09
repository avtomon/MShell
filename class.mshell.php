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
    public static function create ($hostorsock = MEMCACHED_SOCKET, $port = MEMCACHED_PORT, $ttl = MEMCACHED_TTL, $tag_ttl = MEMCACHED_TAG_TTL, $delay = MEMCACHED_DELAY, $solt = MEMCACHED_SOLT)
    {
        if (!self::$instance)
        {
            $dbconnect = _PDO::create();

            $mc = new Memcache;
            if ($connect = $mc->connect($hostorsock, $port))
            {
                self::$instance = new MShell ($mc, $ttl, $tag_ttl, $lock_value = MEMCACHED_LOCK_VALUE, $delay, $solt, $dbconnect);
            }
            else
            {
                throw new MShellException('Подключение не удалось');
            }
        }
        return self::$instance;
    }

    public static function getInstance ()
    {
        return self::$instance;
    }

    /**
     * @param $mc - объект подключения к memcached
     * @param $ttl - время актуальности элемента кэша
     * @param $tag_ttl - время актуальности тега
     * @param $lock_value - значение для блокировки элемента кэша
     * @param $delay - задержка между двумя последовательными запросами блокировки элемента кэша
     * @param $solt - соль для генерации ключа элемента
     * @param $dbconnect - объект подключения к БД
     */
    private function __construct (&$mc, &$ttl, &$tag_ttl, $lock_value, &$delay, &$solt, &$dbconnect)
    {
        $this->dbconnect = $dbconnect;
        $this->mc = $mc;
        $this->ttl = $ttl;
        $this->tag_ttl = $tag_ttl;
        $this->lock_value = $lock_value;
		$this->delay = $delay;
        $this->solt = $solt;
        $this->dbconnect = $dbconnect;      
    }

    public function __destruct ()
    {
        return $this->mc->close();
    }

    /**
     * Функция получения ключа элемента кэша
     *
     * @param $query - текст запроса
     *
     * @return string
     * @throws Exception
     */
    private function getKey ($query)
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
    public function getValue ($query, array $params = array(), $expires = 120)
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
                                foreach ($tags AS $tag)
                                {
                                    if ($this->mc->get($tag) >= $value['ttl'] - $expires)
                                    {
                                        goto get;
                                    }
                                }
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
    private  function initTags (array $tags = array())
    {
        if ($tags)
        {
            foreach ($tags AS $tag)
            {
                if (!$this->mc->set($tag, time(), 0, $this->tag_ttl))
                    throw new MShellException('Не удалось установить значение тега');
            }
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
    private function setValue ($key, $value, $expires)
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
     * @throws Exception
     */
    public function saveHTML($html, $url, $expire)
    {
        return $this->mc->set(md5($url), $html, 0, $expire);
    }

    /**
     * Достать разметку страницы из кэша
     *
     * @param $url - адрес запрашиваемой страницы
     *
     * @throws Exception
     */
    public function getHTML ($url)
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
    public function delHTML ($url)
    {
        return $this->mc->delete(md5($url));
    }

}