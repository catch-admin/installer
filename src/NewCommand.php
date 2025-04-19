<?php

namespace CatchAdmin\Installer\Console;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Composer;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

class NewCommand extends Command
{
    use Concerns\ConfiguresPrompts;
    use Concerns\InteractsWithHerdOrValet;
    use Concerns\InteractsWithComposer;

    /**
     * Composer实例
     *
     * @var Composer
     */
    protected Composer $composer;

    protected string $version = '';

    protected string $mirror = '';

    /**
     * 配置命令选项
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('new')
            ->setDescription('创建新的 catch admin 项目')
            ->addArgument('name', InputArgument::REQUIRED)
            ->addOption('force', 'f', InputOption::VALUE_NONE, '强制创建');
    }

    /**
     * 在验证输入前与用户交互
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     */
    protected function interact(InputInterface $input, OutputInterface $output)
    {
        parent::interact($input, $output);

        $this->configurePrompts($input, $output);

        $output->write('  <fg=red>
  ______                      _                  _         _
 / _____)        _           | |       /\       | |       (_)
| /        ____ | |_    ____ | | _    /  \    _ | | ____   _  ____
| |       / _  ||  _)  / ___)| || \  / /\ \  / || ||    \ | ||  _ \
| \_____ ( ( | || |__ ( (___ | | | || |__| |( (_| || | | || || | | |
 \______) \_||_| \___) \____)|_| |_||______| \____||_|_|_||_||_| |_|</>' . PHP_EOL . PHP_EOL);

        $this->ensureExtensionsAreAvailable($input, $output);

        if (! $input->getArgument('name')) {
            $input->setArgument('name', text(
                label: '请输入项目名称',
                placeholder: '例如: my-project',
                required: '项目名称不能为空',
                validate: function ($value) use ($input) {
                    if (preg_match('/[^\pL\pN\-_.]/', $value) !== 0) {
                        return '项目名称只能包含字母、数字、破折号、下划线和点';
                    }

                    if ($input->getOption('force') !== true) {
                        try {
                            $this->verifyApplicationDoesntExist($this->getInstallationDirectory($value));
                        } catch (RuntimeException $e) {
                            return '项目目录已存在';
                        }
                    }
                },
            ));
        }

        if ($input->getOption('force') !== true) {
            $this->verifyApplicationDoesntExist(
                $this->getInstallationDirectory($input->getArgument('name'))
            );
        }

        $this->version = \Laravel\Prompts\select(
            label: '请选择版本',
            options:  ['laravel', 'webman', 'thinkphp'],
            default: 0
        );

        $isUserMirror = confirm("是否使用镜像?", default: false, yes: 'No');
        if ($isUserMirror) {
            $this->mirror = \Laravel\Prompts\select(
                label: '请选择镜像',
                options:  array_keys($this->mirrors()),
                default: 2
            );
            $output->writeln("国内镜像目前同步都很滞后，如果没有安装成功，请删除镜像" . PHP_EOL);
            $output->writeln("使用该命令 composer config -g --unset repos.packagist" . PHP_EOL);
        }
        $output->writeln(" ");
    }

    /**
     * 确保所需的PHP扩展已安装
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     *
     * @throws \RuntimeException
     */
    protected function ensureExtensionsAreAvailable(InputInterface $input, OutputInterface $output): void
    {
        $availableExtensions = get_loaded_extensions();

        $requiredExtensions = [
            'ctype',
            'filter',
            'hash',
            'mbstring',
            'openssl',
            'session',
            'tokenizer',
        ];

        $missingExtensions = collect($requiredExtensions)
            ->reject(fn ($extension) => in_array($extension, $availableExtensions));

        if ($missingExtensions->isEmpty()) {
            return;
        }

        throw new \RuntimeException(
            sprintf('以下 PHP 扩展是必需的，但尚未安装: %s', $missingExtensions->join(', ', ', 和 '))
        );
    }

    /**
     * 执行命令
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = rtrim($input->getArgument('name'), '/\\');

        $directory = $this->getInstallationDirectory($name);

        $this->composer = new Composer(new Filesystem(), $directory);

        if (! $input->getOption('force')) {
            $this->verifyApplicationDoesntExist($directory);
        }

        if ($input->getOption('force') && $directory === '.') {
            throw new RuntimeException('不能在当前目录使用 --force 选项进行安装！');
        }

        $repoUrl = $this->getOpensourceProject($this->version);
        $createProjectCommand = "git clone {$repoUrl} \"$directory\"";

        $commands = [
            $createProjectCommand,
        ];

        if ($this->mirror) {
            if ($this->mirror == '本地代理') {
                $localProxy = $this->mirrors()[$this->mirror];
                $setCommand = PHP_OS_FAMILY == 'Windows' ? 'set' : 'export';
                $commands[] = "{$setCommand} http_proxy={$localProxy}";
                $commands[] = "{$setCommand} https_proxy={$localProxy}";
            } else {
                // 先取消代理
                $commands[] = $this->findComposer() . " config -g --unset repos.packagist";
                // 设置代理
                $commands[] = $this->findComposer() . " config -g repos.packagist composer " . $this->mirrors()[$this->mirror];
            }
        }

        $commands[] = "cd \"$directory\" && " . $this->findComposer() . " install --ignore-platform-reqs";
        $commands[] = "cd \"$directory\"";

        if ($directory != '.' && $input->getOption('force')) {
            if (PHP_OS_FAMILY == 'Windows') {
                array_unshift($commands, "(if exist \"$directory\" rd /s /q \"$directory\")");
            } else {
                array_unshift($commands, "rm -rf \"$directory\"");
            }
        }

        if (PHP_OS_FAMILY != 'Windows') {
            if ($this->version == 'laravel') {
                $commands[] = "chmod 755 \"$directory/artisan\"";
            }

            if ($this->version == 'webman') {
                $commands[] = "chmod 755 \"$directory/webman\"";
            }

            if ($this->version == 'thinkphp') {
                $commands[] = "chmod 755 \"$directory/think\"";
            }
        }

        if (($process = $this->runCommands($commands, $input, $output))->isSuccessful()) {
            @shell_exec("cd {$directory}");

            $startBin = $this->version == 'laravel' ? 'artisan' : ($this->version == 'webman' ? 'webman' : 'think');

            $output->writeln(" <fg=blue> 🎉 CatchAdmin 已安装完成， 使用「cd {$input->getArgument('name')} && php {$startBin} catch:install 」初始化项目" . PHP_EOL);

            $output->writeln('');
        }

        return $process->getExitCode();
    }

    /**
     * 获取开源项目仓库地址
     *
     * @param $version
     * @return string
     */
    public function getOpensourceProject($version): string
    {
        return [
            'laravel' => 'https://gitee.com/catchadmin/catchAdmin.git',
            'webman' => 'https://gitee.com/catchadmin/catchadmin-webman.git',
            'thinkphp' => 'https://gitee.com/catchadmin/catchadmin-tp.git',
        ][$version];
    }

    /**
     * 获取应用程序的顶级域名
     *
     * @return string
     */
    protected function getTld()
    {
        return $this->runOnValetOrHerd('tld') ?: 'test';
    }

    /**
     * 确定给定主机名是否可解析
     *
     * @param  string  $hostname
     * @return bool
     */
    protected function canResolveHostname($hostname)
    {
        return gethostbyname($hostname.'.') !== $hostname.'.';
    }
}
