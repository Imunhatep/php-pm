<?php

namespace PHPPM\Commands;

use PHPPM\ProcessManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class StartCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('start')
            ->addArgument('working-directory', InputArgument::OPTIONAL, 'The root of your appplication.', './')
            ->addOption('bridge', null, InputOption::VALUE_OPTIONAL, 'The bridge we use to convert a ReactPHP-Request to your target framework.', 'HttpKernel')
            ->addOption('port', null, InputOption::VALUE_OPTIONAL, 'Load-Balancer port. Default is 8080', 8080)
            ->addOption('workers', null, InputOption::VALUE_OPTIONAL, 'Worker count. Default is 8. Should be minimum equal to the number of CPU cores.', 8)
            ->addOption('app-env', null, InputOption::VALUE_OPTIONAL, 'The environment that your application will use to bootstrap (if any)', 'dev')
            ->addOption('bootstrap', null, InputOption::VALUE_OPTIONAL, 'The class that will be used to bootstrap your application', 'PHPPM\Bootstraps\Symfony')
            ->addOption('memory-limit', null, InputOption::VALUE_OPTIONAL, 'The memory limit for one thread', 100)
            ->addOption('memory-check-time', null, InputOption::VALUE_OPTIONAL, 'Time in seconds to check memory limit, by default - no check', 0)
            ->setDescription('Starts the server');
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface   $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($workingDir = $input->getArgument('working-directory')) {
            chdir($workingDir);
        }

        $config = [];
        if (file_exists('./ppm.json')) {
            $config = json_decode(file_get_contents('./ppm.json'), true);
        }

        $bridge = $this->optionOrConfig($input, $config, 'bridge');
        $port = (int)$this->optionOrConfig($input, $config, 'port');
        $workers = (int)$this->optionOrConfig($input, $config, 'workers');
        $appenv = $this->optionOrConfig($input, $config, 'app-env');
        $appBootstrap = $this->optionOrConfig($input, $config, 'bootstrap');
        $memoryLimit = (int)$this->optionOrConfig($input, $config, 'memory-limit');
        $memoryCheckTime = (int)$this->optionOrConfig($input, $config, 'memory-check-time');

        $handler = new ProcessManager($port, $workers);
        $handler->setBridge($bridge)
            ->setAppEnv($appenv)
            ->setAppBootstrap($appBootstrap)
            ->setMemoryLimit($memoryLimit)
            ->setMemoryCheckTime($memoryCheckTime);

        $handler->run();
    }

    /**
     * @param InputInterface $input
     * @param array          $config
     * @param string         $name
     *
     * @return mixed
     */
    private function optionOrConfig(InputInterface $input, $config, $name)
    {
        $val = $input->getOption($name);

        if (!$val && isset($config[$name])) {
            $val = $config[$name];
        }

        return $val;
    }
}
