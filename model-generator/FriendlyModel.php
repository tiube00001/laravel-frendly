<?php

namespace App\Commands;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;


class FriendlyModel extends GeneratorCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'friendly-model:generate';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new Eloquent friendly model class to IDE';

    /**
     * The type of class being generated.
     *
     * @var string
     */
    protected $type = 'Model';

    /**
     * FriendlyModel constructor.
     * @param Filesystem $files
     */
    public function __construct(Filesystem $files)
    {
        parent::__construct($files);
    }

    public function handle()
    {
        if (parent::handle() === false && ! $this->option('force')) {
            return;
        }

        if ($this->option('all')) {
            $this->input->setOption('factory', true);
            $this->input->setOption('migration', true);
            $this->input->setOption('controller', true);
            $this->input->setOption('resource', true);
        }

        if ($this->option('factory')) {
            $this->createFactory();
        }

        if ($this->option('migration')) {
            $this->createMigration();
        }

        if ($this->option('controller') || $this->option('resource')) {
            $this->createController();
        }
    }

    /**
     * Create a model factory for the model.
     *
     * @return void
     */
    protected function createFactory()
    {
        $factory = Str::studly(class_basename($this->argument('name')));

        $this->call('make:factory', [
            'name' => "{$factory}Factory",
            '--model' => $this->argument('name'),
        ]);
    }

    /**
     * Create a migration file for the model.
     *
     * @return void
     */
    protected function createMigration()
    {
        $table = Str::plural(Str::snake(class_basename($this->argument('name'))));

        if ($this->option('pivot')) {
            $table = Str::singular($table);
        }

        $this->call('make:migration', [
            'name' => "create_{$table}_table",
            '--create' => $table,
        ]);
    }

    /**
     * Create a controller for the model.
     *
     * @return void
     */
    protected function createController()
    {
        $controller = Str::studly(class_basename($this->argument('name')));

        $modelName = $this->qualifyClass($this->getNameInput());

        $this->call('make:controller', [
            'name' => "{$controller}Controller",
            '--model' => $this->option('resource') ? $modelName : null,
        ]);
    }


    /**
     * 创建文件
     * @param  string  $name
     * @return string
     */
    protected function buildClass($name)
    {
        $stub = $this->files->get($this->getStub());

        return $this->replaceNamespace($stub, $name)
            ->replaceProperty($stub)
            ->replaceTableName($stub)
            ->replaceConnection($stub)
            ->replaceClass($stub, $name);
    }


    /**
     * 获取模板的路径
     * @return string
     */
    protected function getStub()
    {
        return __DIR__.'/model.stub';
    }

    /**
     * 生成property注释
     * @param $stub
     * @return $this
     */
    protected function replaceProperty(&$stub)
    {
        if ($dc = $this->getOptionConnection()) {
            $query = DB::connection($dc)->table('information_schema.columns');
        } else {
            $query = DB::table('information_schema.columns');
        }

        $list = $query->select([
                'COLUMN_NAME', 'DATA_TYPE', 'COLUMN_COMMENT'
            ])
            ->where('table_name', $this->argument('table'))
            ->where('table_schema', config('database.connections.mysql.database'))
            ->get()->toJson();

        $list = json_decode($list, true);
        $temp = " * @property %s %s %s\n";
        $str = '';
        foreach ($list as $row) {
            $str .= sprintf($temp, $this->getPropertyDataType($row['DATA_TYPE']), '$'.$row['COLUMN_NAME'], $row['COLUMN_COMMENT']);
        }

        $str = trim($str, "\n");

        $stub = str_replace('DummyProperty', $str, $stub);
        return $this;
    }

    /**
     * mysql数据类型简单转换成php类型
     * @param $type
     * @return string
     */
    protected function getPropertyDataType($type)
    {
        $type = strtolower($type);

        if (strpos($type, 'int') !== false || strpos($type, 'year') !== false) {
            return 'int';
        } elseif (in_array($type, ['float', 'double', 'decimal'])) {
            return 'float';
        } elseif (strpos($type, 'char') !== false || strpos($type, 'text') !== false || strpos($type, 'blob') !== false) {
            return 'string';
        } elseif (strpos($type, 'date') !== false || strpos($type, 'time') !== false) {
            return 'string';
        } else {
            return 'string';
        }


    }

    /**
     * 替换表名字占位符
     *
     * @param $stub
     * @return $this
     */
    protected function replaceTableName(&$stub)
    {
        $stub = str_replace('TableName', $this->argument('table'), $stub);
        return $this;
    }

    /**
     * 根据配置参数生产数据库连接属性
     * @param $stub
     * @return $this
     */
    protected function replaceConnection(&$stub)
    {
        $str = '';
        if ($dc = $this->getOptionConnection()) {
            $str = "protected \$connection = '{$dc}';";
        }

        $stub = str_replace('DummyConnection', $str, $stub);
        return $this;
    }

    /**
     * 检查并返回connection配置
     * @return string|false
     */
    protected function getOptionConnection()
    {
        if (!$this->option('connection')) {
            return false;
        }
        $dc = $this->option('connection');
        $connection = config('database.connections.'.$dc);
        if (!$connection) {
            $this->error('Invariable Option Value for -d, Please check the database config file');
            exit(1);
        }
        return $this->option('connection');
    }

    /**
     * Get the default namespace for the class.
     *
     * @param  string  $rootNamespace
     * @return string
     */
    protected function getDefaultNamespace($rootNamespace)
    {
        return $rootNamespace;
    }

    /**
     * Get the console command arguments.
     *
     * @return array
     */
    protected function getArguments()
    {
        return array_merge(parent::getArguments(), [
            ['table', InputArgument::REQUIRED, 'The name of the class']
        ]);
    }

    /**
     * Get the console command options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return [
            ['all', 'a', InputOption::VALUE_NONE, 'Generate a migration, factory, and resource controller for the model'],

            ['controller', 'c', InputOption::VALUE_NONE, 'Create a new controller for the model'],

            ['factory', 'f', InputOption::VALUE_NONE, 'Create a new factory for the model'],

            ['force', null, InputOption::VALUE_NONE, 'Create the class even if the model already exists'],

            ['migration', 'm', InputOption::VALUE_NONE, 'Create a new migration file for the model'],

            ['pivot', 'p', InputOption::VALUE_NONE, 'Indicates if the generated model should be a custom intermediate table model'],

            ['resource', 'r', InputOption::VALUE_NONE, 'Indicates if the generated controller should be a resource controller'],

            ['connection', 'd', InputOption::VALUE_REQUIRED, 'Database connection in database config file']
        ];
    }
}
