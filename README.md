MShell (модуль требует наличия модуля _PDO (https://github.com/avtomon/_PDO)
====
Описание методов
##Класс class.mshell.php
####create
`public static function create ($hostorsock = 'unix:///tmp/memcached.sock', $dbdriver = 'pgsql', $port = 0, $ttl = 300, $tag_ttl = 120, $delay = 10000, $solt = false)`   
######Singleton для объекта класса. Статический метод возвращающий объект класса _PDO  
**Параметры:**
* *$hostorsock* - имя, ip-адрес хоста или UNIX-сокет для подключения к Memcached;
* *$dbdriver* - драйвер доступа к СУБД. На данный момент поддерживаются СУБД MySQL и PostgreSQL, допустимые значения: pgsql, mysql;
* *$port* - порт, на котором Memcached слушает подключения (0 для Unix-сокета);  
* *$ttl* - время жизни элемента кэша;
* *$tag_ttl* - время жизни тега сброса для кэша;
* *$delay* - задержка между двумя последовательными запросами на получение блокировки на запись в кэш;
* *$solt* - дополнительная соль для хэширования ключей кэша

**Пример использования:**    
`$mc = MShell::create();`
