<?php

namespace CatchAdmin\Installer\Console\Concerns;

use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Illuminate\Support\ProcessUtils;

trait InteractsWithComposer
{
    /**
     * 获取可用的镜像列表
     *
     * @return array
     */
    protected function mirrors(): array
    {
        // Try to get proxy information from environment variables
        $httpProxy = getenv('HTTP_PROXY') ?: getenv('http_proxy');
        $httpsProxy = getenv('HTTPS_PROXY') ?: getenv('https_proxy');
        // Fallback to hardcoded mirrors if no proxy settings found

        $localProxy = $httpProxy ?: ($httpsProxy ?: '');
        $mirrors = [
            '腾讯云' => 'https://mirrors.tencent.com/composer/',
            '阿里云' => 'https://mirrors.aliyun.com/composer/',
            '华为云' => 'https://repo.huaweicloud.com/repository/php/'
        ];

        if ($localProxy) {
            $mirrors['本地代理'] = $localProxy;
        }

        return $mirrors;
    }

    /**
     * 获取安装目录
     *
     * @param  string  $name
     * @return string
     */
    protected function getInstallationDirectory(string $name)
    {
        return $name !== '.' ? getcwd().'/'.$name : '.';
    }

    /**
     * 获取环境的composer命令
     *
     * @return string
     */
    protected function findComposer()
    {
        return implode(' ', $this->composer->findComposer());
    }

    /**
     * 获取适当的PHP二进制文件路径
     *
     * @return string
     */
    protected function phpBinary()
    {
        $phpBinary = function_exists('Illuminate\Support\php_binary')
            ? \Illuminate\Support\php_binary()
            : (new PhpExecutableFinder)->find(false);

        return $phpBinary !== false
            ? ProcessUtils::escapeArgument($phpBinary)
            : 'php';
    }

    /**
     * 运行给定的命令
     *
     * @param  array  $commands
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param  string|null  $workingPath
     * @param  array  $env
     * @return \Symfony\Component\Process\Process
     */
    protected function runCommands($commands, InputInterface $input, OutputInterface $output, ?string $workingPath = null, array $env = [])
    {
        if (! $output->isDecorated()) {
            $commands = array_map(function ($value) {
                if (Str::startsWith($value, ['chmod', 'git', $this->phpBinary().' ./vendor/bin/pest'])) {
                    return $value;
                }

                return $value.' --no-ansi';
            }, $commands);
        }

        if ($input->getOption('quiet')) {
            $commands = array_map(function ($value) {
                if (Str::startsWith($value, ['chmod', 'git', $this->phpBinary().' ./vendor/bin/pest'])) {
                    return $value;
                }

                return $value.' --quiet';
            }, $commands);
        }

        $process = Process::fromShellCommandline(implode(' && ', $commands), $workingPath, $env, null, null);

        if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
            try {
                $process->setTty(true);
            } catch (RuntimeException $e) {
                $output->writeln('  <bg=yellow;fg=black> 警告 </> '.$e->getMessage().PHP_EOL);
            }
        }

        $process->run(function ($type, $line) use ($output) {
            $output->write('    '.$line);
        });

        return $process;
    }

    /**
     * 验证应用程序不存在
     *
     * @param  string  $directory
     * @return void
     */
    protected function verifyApplicationDoesntExist($directory)
    {
        if ((is_dir($directory) || is_file($directory)) && $directory != getcwd()) {
            throw new RuntimeException('项目目录已存在！');
        }
    }
}
