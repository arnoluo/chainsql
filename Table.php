<?php

namespace SqlChain;

class Table {
    public static $columns = array();
    public static $tables = array();
    public static $existTable = array();
    public static $tablePos = -1;
    public static $columnPos = -1;
    public static $object = null;
    public static $error = '';
    public static $skip = false;
    public static $config = [
        'prefix' => '',
        'engine' => '',
        'charset' => '',
        'filename' => ''
    ];

    public static function register($params = [])
    {
        $allowedConfig = array_keys(self::$config);
        foreach ($params as $key => $param) {
            if (in_array($key, $allowedConfig)) {
                self::$key($param);
            }   
        }
    }

    public static function prefix($str)
    {
        self::$config['prefix'] = trim($str);
    }

    public static function charset($str)
    {
        self::$config['charset'] = ' DEFAULT CHARSET=' . trim($str);
    }

    public static function engine($str)
    {
        self::$config['engine'] = ' ENGINE=' . trim($str);
    }

    public static function filename($str)
    {
        self::$config['filename'] = trim($str);
    }

    public static function skip()
    {
        self::$skip = true;
    }

    public static function endSkip()
    {
        self::$skip = false;
    }

    public static function create($tableName, $callback)
    {
        $table = self::instance();

        if (self::$skip) {
            return $table;
        }

        if (!self::resolveTableName($tableName, 'create')) {
            return;
        }

        self::setTableValue('begin', self::$columnPos + 1);
        call_user_func($callback, $table);
        self::setTableValue('end', self::$columnPos);
        return $table;
    }

    public static function alter($tableName, $callback)
    {
        $table = self::instance();

        if (self::$skip) {
            return $table;
        }

        if (!self::resolveTableName($tableName, 'alter')) {
            return;
        }

        self::setTableValue('begin', self::$columnPos + 1);
        call_user_func($callback, $table);
        self::setTableValue('end', self::$columnPos);
        return $table;
    }

    public static function drop($tableName)
    {
        $table = self::instance();

        if (self::$skip) {
            return $table;
        }

        if (!self::resolveTableName($tableName, 'drop')) {
            return;
        }
        
        return $table;
    }

    public function tableComment($str)
    {
        if (!self::$skip) {
            self::setTableValue('comment', (string)$str);
        }
    }

    public function __call($method, $params)
    {
        if (in_array($method, self::allowedType())) {
            self::createColumn($params[0], $method, isset($params[1]) ? $params[1] : '');
            return self::instance($method);
        }

        if (in_array($method, self::specialType())) {
            self::createSpecialColumn($method, isset($params[0]) ? $params[0] : '');
            return self::instance($method);
        }

        self::errorMsg("Allowed data type:\n" . json_encode(self::allowedType()));
    }

    public function modify()
    {
        self::$columns[self::$columnPos] = 'MODIFY COLUMN' . self::$columns[self::$columnPos];
    }

    public function uniqueKey()
    {
        self::addColumnParam('UNIQUE');
        return self::instance();
    }

    public function add()
    {
        self::$columns[self::$columnPos] = 'ADD COLUMN' . self::$columns[self::$columnPos];
    }

    public function primaryKey()
    {
        self::addColumnParam('PRIMARY KEY');
        return self::instance();
    }

    public function default($default)
    {
        self::addColumnParam("DEFAULT $default");
        return self::instance();
    }

    public function autoIncrement($offset = 0)
    {
        $offset = $offset > 0 ? ' ' . (int)$offset : '';
        self::addColumnParam("AUTO_INCREMENT" . $offset);
        return self::instance();
    }

    public function unsigned()
    {
        self::addColumnParam("UNSIGNED");
        return self::instance();
    }

    public function __destruct()
    {
        if (!empty(self::$error)) {
            echo (string)self::$error;
            exit;
        }

        self::chain();
    }

    protected static function allowedType()
    {
        return [
            'int',
            'bigInt',
            'mediumInt',
            'smallInt',
            'tinyInt',
            'varchar',
            'char',
            'longText',
            'mediumText',
            'text',
            'tinyText',
            'blob',
            'boolean',
            'date',
            'time',
            'dateTime',
            'timestamp',
            'double',
            'float',
            'ip',
            'json',
            'enum'
        ];
    }

    protected static function specialType()
    {
        return [
            'dropColumn',
            'dropPrimary',
            'dropUnique',
            'unique',
            'primary',
        ];
    }

    protected static function resolveTableName($tableName, $action)
    {
        if ($action === 'create' && in_array($tableName, self::$existTable)) {
            return false;
        }

        if ($action === 'drop' && !in_array($tableName, self::$existTable)) {
            return false;
        }

        self::$tablePos++;

        if ($action === 'create') {
            self::$existTable[] = $tableName;
        }

        self::$tables[self::$tablePos] = [
            'table' => $tableName,
            'action' => $action,
            'comment' => ''
        ];
        
        return true;
    }

    protected static function setTableValue($key, $value)
    {
        self::$tables[self::$tablePos][$key] = $value;
    }

    protected static function createColumn($column, $type, $added = '')
    {
        self::$columnPos++;
        self::$columns[self::$columnPos] = '';
        if (!empty($added)) {
            $type .= '(' . $added . ')';
        }

        self::addColumnParam("`$column`");
        self::addColumnParam(strtolower($type));
    }

    protected static function createSpecialColumn($action, $column)
    {
        self::$columnPos++;
        switch ($action) {
            case 'dropColumn' :
                self::$columns[self::$columnPos] = "DROP COLUMN `$column`";
                break;
            case 'dropPrimary' :
                self::$columns[self::$columnPos] = "DROP PRIMARY KEY";
                break;
            case 'dropUnique' :
                self::$columns[self::$columnPos] = "DROP INDEX `$column`";
                break;
            case 'unique' :
                self::$columns[self::$columnPos] = "ADD UNIQUE (`$column`)";
                break;
            case 'primary' :
                self::$columns[self::$columnPos] = "ADD PRIMARY KEY (`$column`)";
                break;
        }
    }

    protected static function addColumnParam($str)
    {
        self::$columns[self::$columnPos] .= " $str";
    }

    protected static function tableAction($table)
    {
        $sql = self::drawComment($table['comment']);
        $tableName = '`' . self::$config['prefix'] . $table['table'] . '`';

        if ($table['action'] === 'drop') {
            return $sql . strtoupper($table['action']) . ' TABLE ' . $tableName . ";\n\n";
        }

        $sql .= strtoupper($table['action']) . ' TABLE ' . $tableName;
        $sql .= ($table['action'] === 'create') ? "(\n" : "\n"; 
        for ($pos = $table['begin']; $pos < $table['end']; $pos++) {
            $sql .= self::columnAction($pos, $table['action']) . ",\n";
        }
        $sql .= self::columnAction($table['end'], $table['action']) . "\n";
        $sql .= self::finishTableAction($table['action']);

        return $sql;
    }

    protected static function finishTableAction($action)
    {
        $end = '';
        if ($action === 'create') {
            $end .= ')' . self::$config['engine'] . self::$config['charset'];
        }
        $end .= ";\n\n";
        return $end;
    }

    protected static function columnAction($pos, $action)
    {
        return '    ' . self::$columns[$pos];
    }

    protected static function drawComment($comment = '')
    {
        if (empty($comment)) {
            return '';
        }

        $comment = str_replace(' ', '', $comment);
        $comment = str_replace(array("\n", "\r", "\r\n"), "\n * ", $comment);

        return "/**\n * $comment\n */\n";
    }

    protected static function painter($text)
    {
        if (empty($text)) {
            self::render("CHAIN SQL SUCCESS, NOTHING CHANGED\n");
        }

        $fileName = self::$config['filename'];
        if (empty($fileName)) {
            self::render("<pre>$text</pre>");
        }

        if (file_exists($fileName)) {
            self::backupOldFile($fileName);
        }

        file_put_contents($fileName, $text);
        self::render("CHAIN SQL SUCCESS\n");
    }

    protected static function backupOldFile($fileName, $backupPrefix = '')
    {
        if (empty($backupPrefix)) {
            $backupPrefix = date('YmdHis') . mt_rand(10, 99) . "_backup_";
        }
        $backupFile = dirname($fileName) . '/' . $backupPrefix . basename($fileName);

        return rename($fileName, $backupFile);
    }

    protected static function instance()
    {
        if (is_null(self::$object)) {
            self::$object = new self();
        }

        return self::$object;
    }

    protected static function render($msg)
    {
        echo (string)$msg;
        exit();
    }

    protected static function errorMsg($msg)
    {
        self::$error = $msg;
    }

    protected static function chain()
    {
        $sql = '';
        foreach (self::$tables as $table) {
            $sql .= self::tableAction($table);
        }

        self::painter($sql);
    }
}
