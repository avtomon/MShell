MShell (модуль требует наличия модуля _PDO (https://github.com/avtomon/_PDO)
====
Описание методов
##Класс class.mshell.php
####create
`public static function create ($hostorsock = 'unix:///tmp/memcached.sock', $dbdriver = 'pgsql', $port = 0, $ttl = 300, $tag_ttl = 120, $delay = 10000, $solt = false)`   
######Синглтон для получения объекта управления memcached  
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

####getKey
`private function getKey ($query)`
######Функция получения ключа элемента кэша  

####beginTransaction
`public function beginTransaction ()`   
######Стартует транзакцию  

####commit
`public function commit ()`   
######Коммитит транзакцию  

####rollBack
`public function rollBack ()`   
######Откатывает транзакцию  

####setAttribute
`public function setAttribute ($attribute, $value)`   
######Установить атрибут на подключение  
**Параметры:**   
* *$attribute* - имя атрибута;
* *$value* - устанавливаемое значение.

####getValue
`public function getValue ($query, array $params = [], $expires = 120)`   
######Возвращает данные. Если надо лезет за ними в базу, иначе берет из кэша
**Параметры:**   
* *$query* - текст запроса;
* *$params* - массив параметров запроса;
* *$expires* - время, в течение которого элемент кэша считается актульным

####initTags
`private function initTags (array)`   
######Инициализация заданного массива тегов
**Параметры:**   
* *$tags* - заданный массив тегов.

####setValue
`private function setValue ($key, $value, $expires)`   
######Конкурентное сохранение значение в кэше
**Параметры:**   
* *$key* - ключ сохраняемых данных;
* *$value* - сохраняемые данные;
* *$expires* - сколько считать данные актуальными.
* 
####setValue
`private function setValue ($key, $value, $expires)`   
######Конкурентное сохранение значение в кэше
**Параметры:**   
* *$key* - ключ сохраняемых данных;
* *$value* - сохраняемые данные;
* *$expires* - сколько считать данные актуальными.
