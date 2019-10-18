<?php declare(strict_types=1);

namespace PmgDev\Migrations\Command;

use Nette\Utils;
use PmgDev\Migrations\Config;
use PmgDev\Migrations\Factory;
use PmgDev\PsqlCli;
use Symfony\Component\Console\Command;
use Symfony\Component\Console\Input;
use Symfony\Component\Console\Output;

final class RunMigrations extends Command\Command
{
	/** @var string */
	private $migrationsDir;

	/** @var PsqlCli\Command */
	private $psql;


	public function __construct()
	{
		parent::__construct('run');
	}


	protected function configure(): void
	{
		$this->setDescription('Run all needed migrations')
			->addOption('config', NULL, Input\InputOption::VALUE_REQUIRED);
	}


	public function execute(Input\InputInterface $input, Output\OutputInterface $output): int
	{
		/** @var string $configOption */
		$configOption = $input->getOption('config');
		$config = new Config($configOption);
		$factory = new Factory($config);
		['migrationsDir' => $this->migrationsDir] = $config->parameters();
		$this->psql = $factory->createPsql();

		$files = $this->getFiles();
		if ($files === []) {
			$output->writeln('<info>[OK] Your database is up to date.</info>');
		} else {
			foreach ($files as $pathname => $filename) {
				$output->write(sprintf('Importing "%s" .. ', $filename));
				try {
					$this->psql->sql((string) $pathname);
					$output->writeln('<info>OK</info>');
				} catch (\Throwable $e) {
					$output->writeln('<fg=red>FAIL</>');
					$output->writeln($e->getMessage(), Output\OutputInterface::VERBOSITY_VERBOSE);
					return 1;
				}
			}
		}
		return 0;
	}


	/**
	 * @return string[]
	 */
	private function getFiles(): array
	{
		$files = [];
		/** @var \SplFileInfo $file */
		foreach (Utils\Finder::findFiles('*.sql')->from($this->migrationsDir) as $file) {
			$files[$file->getRealPath()] = $file->getFilename();
		}
		asort($files);
		return array_diff($files, array_map('trim', array_slice($this->psql->sql('SELECT filename FROM system.deployed_scripts'), 2, -2)));
	}

}
