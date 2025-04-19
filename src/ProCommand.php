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
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

class ProCommand extends Command
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

    protected string $username;
    protected string $authCode;
    protected string $project;

    protected array $projects = [];

    protected string $mirror = '';

    /**
     * 配置命令选项
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setName('pro')
            ->setDescription('创建新的 catch admin 专业版项目')
            ->addArgument('name', InputArgument::REQUIRED)
            ->addOption('force', 'f', InputOption::VALUE_NONE, '强制创建');
    }

    /**
     * 在验证输入前与用户交互
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return void
     * @throws GuzzleException
     */
    protected function interact(InputInterface $input, OutputInterface $output)
    {
        parent::interact($input, $output);

        $this->configurePrompts($input, $output);

        $output->write('  <fg=red>
 _______                       _      _______      _         _           ______   ______   _______
(_______)          _          | |    (_______)    | |       (_)         (_____ \ (_____ \ (_______)
 _        _____  _| |_   ____ | |__   _______   __| | ____   _  ____     _____) ) _____) ) _     _
| |      (____ |(_   _) / ___)|  _ \ |  ___  | / _  ||    \ | ||  _ \   |  ____/ |  __  / | |   | |
| |_____ / ___ |  | |_ ( (___ | | | || |   | |( (_| || | | || || | | |  | |      | |  \ \ | |___| |
 \______)\_____|   \__) \____)|_| |_||_|   |_| \____||_|_|_||_||_| |_|  |_|      |_|   |_| \_____/

 </>' . PHP_EOL . PHP_EOL);

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

        // 输入邮箱和授权码
        $this->username = text(
            label: '请输入邮箱',
            placeholder: '您的 CatchAdmin 专业版账号邮箱',
            required: '邮箱不能为空',
            validate: function ($value) {
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return '请输入有效的邮箱地址';
                }
            }
        );

        $this->authCode = text(
            label: '请输入授权码',
            placeholder: '您的 CatchAdmin 专业版授权码',
            required: '授权码不能为空'
        );

        // 添加版本选择框
        $this->projects = $this->getAvailableProjects();
        $this->project = \Laravel\Prompts\select(
            label: '请选择版本',
            options:  $this->projects,
            default: 1
        );

        $isUserMirror = confirm("是否使用镜像?", default: false, yes: 'No');
        if ($isUserMirror) {
            $this->mirror = \Laravel\Prompts\select(
                label: '请选择镜像',
                options:  array_keys($this->mirrors()),
                default: 2
            );
            $output->writeln("国内镜像目前同步都很滞后，如果没有安装成功，请删除镜像" . PHP_EOL);
            $output->writeln("使用该命令 composer config -g --unset repos.packagist". PHP_EOL);
        }

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
            'gd', // 专业版需要 GD 扩展
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

        $project = $this->auth($this->project);
        if (! $project) {
            throw new RuntimeException('账户认证失败！');
        }

        $createProjectCommand = "git clone {$project} \"$directory\"";

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

        $commands[] = "cd \"$directory\"";
        $commands[] = $this->phpBinary() . " auth {$this->username} {$this->authCode}";

        if ($directory != '.' && $input->getOption('force')) {
            if (PHP_OS_FAMILY == 'Windows') {
                array_unshift($commands, "(if exist \"$directory\" rd /s /q \"$directory\")");
            } else {
                array_unshift($commands, "rm -rf \"$directory\"");
            }
        }

        if (($process = $this->runCommands($commands, $input, $output))->isSuccessful()) {
            @shell_exec("cd {$directory}");
            $output->writeln(" <fg=blue> 🎉 CatchAdmin Pro 已安装完成. 使用「cd {$input->getArgument('name')} && php artisan catch:install 」初始化项目" . PHP_EOL);
        }

        return $process->getExitCode();
    }

    /**
     * 用户认证
     *
     * @param $project
     * @return string|bool
     * @throws \Exception
     */
    protected function auth($project)
    {
        $client = new Client();

        try {
            $response = $client->post(
                'https://catchadmin.vip/pro/account/create/project/auth',
                [
                    'form_params' => [
                        'email' => $this->username,
                        'password' => $this->authCode,
                        'repo' => $project
                    ]
                ]
            );

            if ($response->getStatusCode() !== 200) {
                return false;
            }

            return $response->getBody()->getContents();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 获取可用的项目版本
     *
     * @return array
     * @throws RuntimeException|GuzzleException
     */
    protected function getAvailableProjects(): array
    {
        $client = new Client();

        $response = $client->get('https://catchadmin.vip/pro/account/projects');

        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('获取可用的项目版本失败');
        }

        $projects = $response->getBody()->getContents();

        return json_decode($projects,true);
    }
}
