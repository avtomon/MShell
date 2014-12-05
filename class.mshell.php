<?php

class MShellException extends Exception { }

class MShell
{
    private $dbconnect;
    private $ttl;
    private $tag_ttl;
    private $lock_value;
    private $delay;
    private $solt;
    
    private static $instance = false;

    /**
     * Синглтон для получения объекта управления memcached
     *
     * @param string $hostorsock
     * @param string $dbdriver
     * @param int $port
     * @param int $ttl
     * @param int $tag_ttl
     * @param int $delay
     * @param bool $solt
     * @return bool|MShell
     * @throws MShellException
     */
    public static function create ($hostorsock = 'unix:///tmp/memcached.sock', $dbdriver = 'pgsql', $port = 0, $ttl = 300, $tag_ttl = 120, $delay = 10000, $solt = false)
    {
        if (!self::$instance)
        {
            $dbconnect = _PDO::create($dbdriver);

            if (file_exists($_SERVER['DOCUMENT_ROOT'].'/confs/memcached.ini') && $init = parse_ini_file($_SERVER['DOCUMENT_ROOT'].'/confs/memcached.ini'))
            {
                extract($init);
            }

            $mc = new Memcache;
            if ($connect = $mc->connect($hostorsock, $port))
            {
                self::$instance = new MShell ($mc, $ttl, $tag_ttl, $lock_value = "906a58a0aac5281e89718496686bb322", $delay, $solt, $dbconnect);
            }
            else
            {
                throw new MShellException('Подключение не удалось');
            }
        }
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
        $this->mc->close();
    }

    /**
     * Функция получения ключа элемента кэша
     *
     * @param $query
     * @return string
     * @throws MShellException
     */
    private function getKey ($query)
    {
        if ($query)
        {
            $key = md5($query . $this->solt);

            return $key;
        }
        else
        {
            throw new MShellException('Строка запроса пуста');
        }
    }

    /**
     * Стартовать транзакцию
     *
     * @return mixed
     */
    public function beginTransaction ()
    {
        return $this->dbconnect->beginTransaction();
    }

    /**
     * Закоммитить транзакцию
     */
    public function commit ()
    {
        return $this->dbconnect->commit();
    }

    /**
     * Откатить транзакцию
     */
    public function rollBack ()
    {
        return $this->dbconnect->rollBack();
    }

    /**
     *
     *
     * @param $attribute
     * @param $value
     * @return mixed
     */
    function setAttribute ($attribute, $value)
    {
        return $this->dbconnect->setAttribute($attribute, $value);
    }

    /**
     * Возвращает данные
     *
     * @param $query
     * @param array $params
     * @param int $expires
     * @return mixed
     * @throws MShellException
     */
    public function getValue ($query, array $params = array(), $expires = 120)
    {
        $key = $this->getKey($query . serialize($params));

        $tags = $this->dbconnect->getEditTables($query);
        if ($tags)
        {
            $this->initTags($tags);
            $tmp = $this->dbconnect->query($query, $params);
            return $tmp;
        }
        else
        {
            if ($expires)
            {
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
                                        goto get;
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
     * @return bool
     * @throws MShellException
     */
    private  function initTags (array $tags = array())
    {
        if ($tags)
        {
            foreach ($tags AS $tag)
            {
                if ($this->mc->set($tag, time(), 0, $this->tag_ttl))
                    return true;
                else
                    throw new MShellException('Не удалось установить значение тега');
            }
        }
        else
        {
            throw new MShellException('Теги отсутствуют');
        }
    }

    /**
     * Конкурентное сохранение значение в кэше
     *
     * @param $key
     * @param $value
     * @param $expires
     * @throws MShellException
     */
    private function setValue ($key, $value, $expires)
    {
        if ($key)
        {
            if ($value)
            {
                $value = array('data' => $value, 'ttl' => time() + $expires);
                if (!$this->mc->set($key, json_encode($value, JSON_UNESCAPED_UNICODE), 0, $this->ttl))
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
     * @param $html
     * @param $url
     * @param $expire
     */
    public function saveHTML($html, $url, $expire)
    {
        $this->mc->set($url, $html, 0, $expire);
    }

    /**
     * Достать разметку страницы из кэша
     *
     * @param $url
     */
    public function getHTML ($url)
    {
        $this->mc->get($url);
    }

}

?>